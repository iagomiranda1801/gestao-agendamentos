<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Filament\App\Resources\TattooAiConversations\TattooAiConversationResource;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\FinancialAccount;
use App\Models\Professional;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Models\TattooPaymentReceipt;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Services\Tattoo\TattooAIConversationService;
use App\Services\Tattoo\TattooAISchedulingService;
use App\Services\Tattoo\TattooReceiptService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class TattooAIFlowTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private function setupAI(): array
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'ai-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key']);

        return [$company, $instance, '5511999999999'];
    }

    private function send($company, $instance, string $phone, string $text, string $id, ?string $mime = null): void
    {
        app(TattooAIConversationService::class)->handle($company, $instance, $phone.'@s.whatsapp.net',
            $phone, $text, $id, $mime);
    }

    public function test_multiturn_collection_creates_one_unpriced_request_and_deduplicates_message(): void
    {
        [$company, $instance, $phone] = $this->setupAI();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'generateContent')) {
                $body = count(TattooAiMessage::query()->where('direction', 'in')->get());
                $details = match ($body) {
                    1 => ['name' => 'Ana'],
                    2 => ['description' => 'Uma rosa com linhas finas'],
                    3 => ['body_placement' => 'Antebraço'],
                    default => ['size_description' => '15 cm'],
                };

                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'action' => 'save_details', 'details' => $details, 'reply' => 'Conte mais.',
                ])]]]]], 'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 10]]);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });
        foreach (['Ana', 'Quero uma rosa', 'No antebraço', '15 cm'] as $i => $text) {
            $this->send($company, $instance, $phone, $text, 'm'.$i);
        }
        $this->send($company, $instance, $phone, '15 cm', 'm3');
        $this->assertDatabaseCount('tattoo_requests', 1);
        $this->assertDatabaseCount('tattoo_quotes', 0);
        $this->assertSame('waiting_professional_quote', TattooAiConversation::query()->firstOrFail()->status);
        $this->assertSame(4, TattooAiMessage::query()->where('direction', 'out')->count());
    }

    public function test_unknown_action_does_not_execute_and_invalid_json_keeps_message_for_retry(): void
    {
        [$company, $instance, $phone] = $this->setupAI();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'generateContent')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => '{"action":"execute_sql","reply":"ok"}']]]]]]);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });
        $this->send($company, $instance, $phone, 'Oi', 'unknown');
        $this->assertDatabaseCount('tattoo_requests', 0);
        $this->assertStringContainsString('encaminhar', TattooAiMessage::query()->where('direction', 'out')->firstOrFail()->body);

    }

    public function test_invalid_json_keeps_message_for_retry(): void
    {
        [$company, $instance, $phone] = $this->setupAI();
        Http::fake(['*generateContent' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'invalid']]]]]])]);
        try {
            $this->send($company, $instance, $phone, 'Nova ideia', 'invalid');
            $this->fail('Expected invalid JSON exception.');
        } catch (\RuntimeException) {
            $this->assertDatabaseHas('tattoo_ai_messages', ['provider_message_id' => 'invalid', 'status' => 'received']);
        }
    }

    public function test_human_takeover_records_messages_without_calling_gemini_or_replying(): void
    {
        [$company, $instance, $phone] = $this->setupAI();
        $conversation = TattooAiConversation::query()->create(['company_id' => $company->id,
            'company_whatsapp_instance_id' => $instance->id, 'phone_normalized' => $phone,
            'remote_jid' => $phone.'@s.whatsapp.net', 'human_takeover' => true]);
        Http::fake();
        $this->send($company, $instance, $phone, 'Preciso de ajuda', 'human-1');
        $this->assertDatabaseHas('tattoo_ai_messages', ['provider_message_id' => 'human-1',
            'tattoo_ai_conversation_id' => $conversation->id, 'status' => 'processed']);
        $this->assertDatabaseCount('tattoo_ai_messages', 1);
        Http::assertNothingSent();
    }

    public function test_panel_lists_tenant_conversation_for_company_admin(): void
    {
        [$company, $instance, $phone] = $this->setupAI();
        $user = $this->createCompanyUser($company);
        TattooAiConversation::query()->create(['company_id' => $company->id,
            'company_whatsapp_instance_id' => $instance->id, 'phone_normalized' => $phone,
            'remote_jid' => $phone.'@s.whatsapp.net']);
        $this->authenticateForAppTenant($user, $company);
        $this->get(TattooAiConversationResource::getUrl('index'))
            ->assertOk()->assertSee($phone);
    }

    public function test_gemini_failure_keeps_incoming_message_for_retry(): void
    {
        [$company, $instance, $phone] = $this->setupAI();
        Http::fake(['*generateContent' => Http::response(['error' => 'unavailable'], 503)]);
        try {
            $this->send($company, $instance, $phone, 'Quero uma tattoo', 'failed-1');
            $this->fail('Expected API failure.');
        } catch (RequestException) {
            $this->assertDatabaseHas('tattoo_ai_messages', ['provider_message_id' => 'failed-1', 'status' => 'received']);
            $this->assertDatabaseCount('tattoo_ai_messages', 1);
        }
    }

    public function test_model_generated_price_is_not_sent_to_customer(): void
    {
        [$company, $instance, $phone] = $this->setupAI();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'generateContent')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'action' => 'ask', 'details' => [], 'reply' => 'Vai custar R$ 500.',
                ])]]]]]]);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });
        $this->send($company, $instance, $phone, 'Quanto custa?', 'price-1');
        $reply = TattooAiMessage::query()->where('provider_message_id', 'reply:price-1')->firstOrFail()->body;
        $this->assertStringNotContainsString('R$ 500', $reply);
        $this->assertStringContainsString('Como posso te chamar?', $reply);
    }

    public function test_receipt_analysis_never_confirms_payment_and_human_review_does(): void
    {
        [$company, $instance, $phone] = $this->setupAI();
        Queue::fake();
        Storage::fake('local');
        config(['filesystems.tattoo_disk' => 'local']);
        $user = $this->createCompanyUser($company);
        $client = Client::factory()->forCompany($company)->create(['phone' => $phone]);
        $request = new TattooRequest(['client_id' => $client->id, 'description' => 'Rosa',
            'body_placement' => 'Braço', 'status' => 'accepted']);
        $request->company_id = $company->id;
        $request->save();
        $quote = new TattooQuote(['version' => 1, 'price_type' => 'fixed', 'amount_min' => 400,
            'deposit_amount' => 100, 'message_snapshot' => 'Orçamento', 'accepted_at' => now()]);
        $quote->company_id = $company->id;
        $quote->tattoo_request_id = $request->id;
        $quote->save();
        $conversation = TattooAiConversation::query()->create(['company_id' => $company->id,
            'company_whatsapp_instance_id' => $instance->id, 'phone_normalized' => $phone,
            'remote_jid' => $phone.'@s.whatsapp.net', 'client_id' => $client->id,
            'tattoo_request_id' => $request->id]);
        FinancialAccount::factory()->forCompany($company)->create(['pix_key' => 'test@example.com',
            'pix_recipient_name' => 'Estudio', 'is_default_receipt_account' => true]);
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/L9sAAAAASUVORK5CYII=');
        Http::fake(function ($httpRequest) use ($image) {
            if (str_contains($httpRequest->url(), 'getBase64FromMediaMessage')) {
                return Http::response(['base64' => base64_encode($image)]);
            }
            if (str_contains($httpRequest->url(), 'generateContent')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'document_type' => 'pix_receipt', 'readable' => true, 'amount' => 90,
                    'recipient_name' => 'Estudio', 'warnings' => [],
                ])]]]]]]);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });
        $this->send($company, $instance, $phone, '', 'receipt-1', 'image/png');
        $receipt = TattooPaymentReceipt::query()->firstOrFail();
        $this->assertSame('inconsistent', $receipt->receipt_analysis_status);
        $this->assertSame('receipt_received', $receipt->payment_status);
        $this->send($company, $instance, $phone, '', 'receipt-1', 'image/png');
        $this->assertDatabaseCount('tattoo_payment_receipts', 1);
        app(TattooReceiptService::class)->review($receipt, $user, true);
        $this->assertSame('confirmed', $receipt->fresh()->payment_status);
    }

    public function test_booking_requires_confirmed_receipt_and_rechecks_real_availability(): void
    {
        [$company, $instance, $phone] = $this->setupAI();
        $user = $this->createCompanyUser($company);
        $client = Client::factory()->forCompany($company)->create(['phone' => $phone]);
        $professional = Professional::factory()->forCompany($company)->bookable()->active()->create();
        $this->seedStandardScheduling($company, $professional);
        $request = new TattooRequest(['client_id' => $client->id, 'professional_id' => $professional->id,
            'description' => 'Rosa', 'body_placement' => 'Braço', 'status' => 'accepted']);
        $request->company_id = $company->id;
        $request->save();
        $quote = new TattooQuote(['version' => 1, 'price_type' => 'fixed', 'amount_min' => 400,
            'deposit_amount' => 100, 'minutes_per_session' => 60, 'message_snapshot' => 'Orçamento',
            'accepted_at' => now(), 'created_by' => $user->id]);
        $quote->company_id = $company->id;
        $quote->tattoo_request_id = $request->id;
        $quote->save();
        $conversation = TattooAiConversation::query()->create(['company_id' => $company->id,
            'company_whatsapp_instance_id' => $instance->id, 'phone_normalized' => $phone,
            'remote_jid' => $phone.'@s.whatsapp.net', 'client_id' => $client->id,
            'tattoo_request_id' => $request->id, 'status' => 'waiting_payment_receipt']);
        $scheduler = app(TattooAISchedulingService::class);
        $this->assertSame([], $scheduler->slots($conversation));
        $receipt = new TattooPaymentReceipt(['tattoo_request_id' => $request->id, 'tattoo_quote_id' => $quote->id,
            'tattoo_ai_conversation_id' => $conversation->id, 'whatsapp_message_id' => 'paid-1',
            'disk' => 'local', 'path' => 'test', 'mime_type' => 'image/png', 'size_bytes' => 10,
            'payment_status' => 'confirmed']);
        $receipt->company_id = $company->id;
        $receipt->save();
        $slots = $scheduler->slots($conversation);
        $this->assertNotEmpty($slots);
        try {
            $scheduler->book($conversation, '2026-01-01 00:00');
            $this->fail('Expected unavailable slot.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('appointments', 0);
        }
        $scheduler->book($conversation, $slots[0]);
        $this->assertDatabaseCount('appointments', 1);
        $this->assertSame('booked', $request->fresh()->status);
    }

    public function test_pix_is_sent_only_after_customer_accepts_a_human_quote(): void
    {
        [$company, $instance, $phone] = $this->setupAI();
        $client = Client::factory()->forCompany($company)->create(['phone' => $phone]);
        $request = new TattooRequest(['client_id' => $client->id, 'description' => 'Rosa',
            'body_placement' => 'Braço', 'status' => 'quote_sent']);
        $request->company_id = $company->id;
        $request->save();
        $quote = new TattooQuote(['version' => 1, 'price_type' => 'fixed', 'amount_min' => 400,
            'deposit_amount' => 100, 'message_snapshot' => 'Orçamento', 'sent_at' => now()]);
        $quote->company_id = $company->id;
        $quote->tattoo_request_id = $request->id;
        $quote->save();
        TattooAiConversation::query()->create(['company_id' => $company->id,
            'company_whatsapp_instance_id' => $instance->id, 'phone_normalized' => $phone,
            'remote_jid' => $phone.'@s.whatsapp.net', 'client_id' => $client->id,
            'tattoo_request_id' => $request->id]);
        FinancialAccount::factory()->forCompany($company)->create(['pix_key' => 'pix@example.com',
            'pix_recipient_name' => 'Estudio', 'is_default_receipt_account' => true]);
        Http::fake(['evolution.test/*' => Http::response(['key' => ['id' => 'sent']])]);
        $this->send($company, $instance, $phone, 'Qual é o PIX?', 'pix-before');
        $this->assertStringNotContainsString('pix@example.com', TattooAiMessage::query()->where('provider_message_id', 'reply:pix-before')->firstOrFail()->body);
        $this->send($company, $instance, $phone, 'aceito', 'pix-after');
        $this->assertStringContainsString('pix@example.com', TattooAiMessage::query()->where('provider_message_id', 'reply:pix-after')->firstOrFail()->body);
        $this->assertSame('accepted', $request->fresh()->status);
    }
}
