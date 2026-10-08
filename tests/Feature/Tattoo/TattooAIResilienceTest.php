<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\FinancialAccount;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Models\TattooPaymentReceipt;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Models\User;
use App\Services\Tattoo\TattooAIConversationService;
use App\Services\Tattoo\TattooReceiptService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

/**
 * Com o Gemini fora do ar (cota 429, 500, JSON inválido, tipos errados) ou
 * erro interno, o cliente do estúdio nunca fica sem resposta.
 */
class TattooAIResilienceTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private string $phone = '5511977776666';

    /** @return array{0: Company, 1: CompanyWhatsAppInstance} */
    private function setupStudio(): array
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $company->schedulingSetting()->updateOrCreate([], ['ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key']);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'tattoo-resilience',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        Storage::fake('local');
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key', 'filesystems.tattoo_disk' => 'local']);

        return [$company, $instance];
    }

    /**
     * @param  list<\Closure(): mixed>  $gemini  respostas do Gemini, em ordem (depois disso: 429)
     */
    private function fakeHttp(array $gemini, ?string $mediaBase64 = null): void
    {
        $queue = $gemini;
        Http::fake(function (Request $request) use (&$queue, $mediaBase64) {
            if (str_contains($request->url(), 'generateContent')) {
                $next = array_shift($queue);

                return $next ? $next() : Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429);
            }
            if (str_contains($request->url(), 'getBase64FromMediaMessage')) {
                return Http::response(['base64' => $mediaBase64 ?? 'nao-e-base64!!']);
            }

            return Http::response(['key' => ['id' => 'sent-'.uniqid()]]);
        });
    }

    private function geminiJson(mixed $payload): \Closure
    {
        $text = is_string($payload) ? $payload : json_encode($payload);

        return fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
    }

    private function send(Company $company, CompanyWhatsAppInstance $instance, string $text, string $id, ?string $mime = null): string
    {
        app(TattooAIConversationService::class)->handle($company, $instance, $this->phone.'@s.whatsapp.net',
            $this->phone, $text, $id, $mime);
        $this->assertDatabaseHas('tattoo_ai_messages', ['provider_message_id' => $id, 'status' => 'processed']);
        $this->assertDatabaseHas('tattoo_ai_messages', ['provider_message_id' => 'reply:'.$id, 'status' => 'sent']);

        return (string) TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('body');
    }

    private function geminiCalls(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'generateContent'))->count();
    }

    public function test_whole_collection_flow_keeps_going_while_gemini_quota_is_exhausted(): void
    {
        [$company, $instance] = $this->setupStudio();
        Log::spy();
        $this->fakeHttp([]);

        $this->assertSame('Opa, tudo bem? Qual seu nome?', $this->send($company, $instance, 'Oi', 't1'));
        $this->assertSame('Tudo certo por aqui, valeu! Qual seu nome?', $this->send($company, $instance, 'bem e vc?', 't2'));
        $this->assertSame(0, $this->geminiCalls());

        $this->assertSame('Show, Ana! Me conta como você imagina a tattoo?', $this->send($company, $instance, 'Ana Souza', 't3'));
        $this->assertDatabaseHas('clients', ['company_id' => $company->id, 'name' => 'Ana Souza', 'phone_normalized' => $this->phone]);
        $this->assertSame('Massa! E vai ser em qual parte do corpo?', $this->send($company, $instance, 'Uma rosa fineline com folhas', 't4'));
        $this->assertSame('E mais ou menos de que tamanho? Pode ser em cm mesmo.', $this->send($company, $instance, 'Antebraço', 't5'));
        $this->assertStringContainsString('Qual estilo', $this->send($company, $instance, '15 cm', 't6'));
        $this->assertStringContainsString('referência ou inspiração', $this->send($company, $instance, 'fine line', 't7'));
        $this->assertStringContainsString('Deixa eu confirmar', $this->send($company, $instance, 'sem foto', 't8'));
        $this->assertStringContainsString('A equipe recebeu sua ideia', $this->send($company, $instance, 'sim', 't9'));

        $request = TattooRequest::query()->firstOrFail();
        $this->assertSame('Uma rosa fineline com folhas', $request->description);
        $this->assertSame('Antebraço', $request->body_placement);
        $this->assertSame('15 cm', $request->size_description);
        $this->assertSame('awaiting_review', $request->status);
        $this->assertDatabaseCount('tattoo_quotes', 0);
        $this->assertSame(6, $this->geminiCalls());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Tattoo AI fallback reply.'
            && $context['reason'] === 'model_unavailable' && $context['http_status'] === 429)->times(6);
    }

    public function test_server_error_invalid_json_and_wrong_types_never_leave_the_client_without_reply(): void
    {
        [$company, $instance] = $this->setupStudio();
        Client::factory()->forCompany($company)->create(['name' => 'Bruno', 'phone' => $this->phone]);
        Log::spy();
        $this->fakeHttp([
            fn () => Http::response('upstream error', 500),
            $this->geminiJson('isso não é json'),
            $this->geminiJson('[{"action":"ask","reply":"Oi"}]'),
            $this->geminiJson(['action' => 'save_details', 'details' => ['size_description' => ['20'], 'style' => 'Realismo'], 'reply' => ['x']]),
            $this->geminiJson(['action' => 'execute_sql', 'reply' => 'ok']),
        ]);

        $this->assertSame('Me conta como você imagina a tattoo?', $this->send($company, $instance, 'Quero fazer uma tattoo', 'w1'));
        $this->assertSame('Massa! E vai ser em qual parte do corpo?', $this->send($company, $instance, 'Um leão realista', 'w2'));
        $this->assertSame('E mais ou menos de que tamanho? Pode ser em cm mesmo.', $this->send($company, $instance, 'Nas costas', 'w3'));
        $this->assertSame('E mais ou menos de que tamanho? Pode ser em cm mesmo.', $this->send($company, $instance, 'uns 20', 'w4'));
        $this->assertStringContainsString('referência ou inspiração', $this->send($company, $instance, '20 cm', 'w5'));
        $this->assertStringContainsString('Deixa eu confirmar', $this->send($company, $instance, 'sem foto', 'w6'));
        $this->assertStringContainsString('A equipe recebeu sua ideia', $this->send($company, $instance, 'sim', 'w7'));

        $request = TattooRequest::query()->firstOrFail();
        $this->assertSame('Um leão realista', $request->description);
        $this->assertSame('Nas costas', $request->body_placement);
        $this->assertSame('20 cm', $request->size_description);
        $this->assertStringContainsString('Realismo', (string) $request->notes);
        $this->assertFalse((bool) TattooAiConversation::query()->firstOrFail()->human_takeover);
        $this->assertSame(6, $this->geminiCalls());
        foreach (['model_unavailable' => 4, 'invalid_details' => 1, 'invalid_action' => 1] as $reason => $times) {
            Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Tattoo AI fallback reply.'
                && $context['reason'] === $reason)->times($times);
        }
    }

    public function test_internal_error_falls_back_to_flow_question_or_instability_message(): void
    {
        [$company, $instance] = $this->setupStudio();
        Log::spy();
        $this->fakeHttp([]);

        // Referência antes do pedido: a mídia não baixa, mas o roteiro segue.
        $this->assertSame('Opa, tudo bem? Qual seu nome?', $this->send($company, $instance, '', 'x1', 'image/jpeg'));

        $client = Client::factory()->forCompany($company)->create(['name' => 'Carla', 'phone' => $this->phone]);
        $request = new TattooRequest(['client_id' => $client->id, 'description' => 'Rosa', 'body_placement' => 'Braço',
            'status' => 'accepted']);
        $request->company_id = $company->id;
        $request->save();
        TattooAiConversation::query()->firstOrFail()->update(['tattoo_request_id' => $request->id, 'client_id' => $client->id]);

        $this->assertSame('Opa, deu uma travadinha aqui 😅 Me manda de novo sua mensagem?', $this->send($company, $instance, '', 'x2', 'image/png'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Tattoo AI fallback reply.'
            && $context['reason'] === 'unexpected_error')->twice();
    }

    /** @return array{0: Company, 1: CompanyWhatsAppInstance, 2: TattooQuote, 3: User} */
    private function waitingReceipt(): array
    {
        [$company, $instance] = $this->setupStudio();
        $admin = $this->createCompanyUser($company);
        $client = Client::factory()->forCompany($company)->create(['name' => 'Dani', 'phone' => $this->phone]);
        $request = new TattooRequest(['client_id' => $client->id, 'description' => 'Rosa', 'body_placement' => 'Braço',
            'status' => 'accepted']);
        $request->company_id = $company->id;
        $request->save();
        $quote = new TattooQuote(['version' => 1, 'price_type' => 'fixed', 'amount_min' => 400, 'deposit_amount' => 100,
            'message_snapshot' => 'Orçamento', 'sent_at' => now()->subHour(), 'accepted_at' => now()->subMinutes(30)]);
        $quote->company_id = $company->id;
        $quote->tattoo_request_id = $request->id;
        $quote->save();
        TattooAiConversation::query()->create(['company_id' => $company->id, 'company_whatsapp_instance_id' => $instance->id,
            'phone_normalized' => $this->phone, 'remote_jid' => $this->phone.'@s.whatsapp.net', 'client_id' => $client->id,
            'tattoo_request_id' => $request->id, 'status' => 'waiting_payment_receipt']);
        FinancialAccount::factory()->forCompany($company)->create(['pix_key' => 'pix@estudio.test',
            'pix_recipient_name' => 'Estudio', 'is_default_receipt_account' => true]);

        return [$company, $instance, $quote, $admin];
    }

    private function pngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/L9sAAAAASUVORK5CYII=';
    }

    public function test_receipt_is_kept_for_manual_review_and_staff_is_told_when_analysis_fails(): void
    {
        [$company, $instance, , $admin] = $this->waitingReceipt();
        Log::spy();
        $this->fakeHttp([], $this->pngBase64());

        $reply = $this->send($company, $instance, '', 'pix-1', 'image/png');
        $this->assertSame('Recebi o comprovante! Vou conferir aqui e já te falo.', $reply);

        $receipt = TattooPaymentReceipt::query()->firstOrFail();
        $this->assertSame('pending', $receipt->receipt_analysis_status);
        $this->assertSame('receipt_received', $receipt->payment_status);
        $this->assertTrue($receipt->analysis['analysis_failed']);
        Storage::disk('local')->assertExists($receipt->path);
        $this->assertSame('receipt_received', TattooAiConversation::query()->firstOrFail()->status);
        $notifications = User::query()->findOrFail($admin->id)->notifications()->get();
        $this->assertTrue($notifications->contains(fn ($notification) => str_contains(
            json_encode($notification->data, JSON_UNESCAPED_UNICODE), TattooReceiptService::ANALYSIS_FAILED_WARNING,
        )));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Tattoo receipt analysis failed.'
            && $context['http_status'] === 429)->once();

        // A equipe confere na mão normalmente.
        app(TattooReceiptService::class)->review($receipt, $admin, true);
        $this->assertSame('confirmed', $receipt->fresh()->payment_status);
        $this->assertSame('payment_confirmed', TattooAiConversation::query()->firstOrFail()->status);
    }

    public function test_receipt_with_invalid_analysis_json_is_also_kept_pending(): void
    {
        [$company, $instance] = $this->waitingReceipt();
        $this->fakeHttp([$this->geminiJson('{"readable":"talvez","amount":"cem"}')], $this->pngBase64());

        $this->assertSame('Recebi o comprovante! Vou conferir aqui e já te falo.', $this->send($company, $instance, '', 'pix-2', 'image/png'));
        $receipt = TattooPaymentReceipt::query()->firstOrFail();
        $this->assertSame('pending', $receipt->receipt_analysis_status);
        $this->assertTrue($receipt->analysis['analysis_failed']);
    }

    public function test_receipt_that_cannot_be_downloaded_asks_to_resend(): void
    {
        [$company, $instance] = $this->waitingReceipt();
        $this->fakeHttp([]);

        $reply = $this->send($company, $instance, '', 'pix-3', 'image/png');
        $this->assertStringContainsString('Me manda o comprovante de novo?', $reply);
        $this->assertDatabaseCount('tattoo_payment_receipts', 0);
        $this->assertSame('waiting_payment_receipt', TattooAiConversation::query()->firstOrFail()->status);
    }
}
