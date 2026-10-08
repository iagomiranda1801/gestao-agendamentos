<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\FinancialAccount;
use App\Models\TattooAiConversation;
use App\Models\TattooPaymentReceipt;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Models\User;
use App\Services\Tattoo\TattooReceiptService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class TattooReceiptFraudTest extends TestCase
{
    use CreatesSchedulingFixtures;

    /** @var array<string, mixed> */
    private array $extraction = [];

    protected function setUp(): void
    {
        parent::setUp();
        // 07/10/2026 15:00 em São Paulo; o orçamento é aceito às 13:00.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 15:00:00', 'America/Sao_Paulo'));
        Storage::fake('local');
        config(['filesystems.tattoo_disk' => 'local', 'services.gemini.api_key' => 'test-key']);
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'generateContent')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($this->extraction)]]]]]]);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });
    }

    /** @return array<string, mixed> */
    private function cleanExtraction(array $overrides = []): array
    {
        return array_merge([
            'document_type' => 'pix_receipt', 'readable' => true, 'amount' => 100,
            'transaction_date' => '2026-10-07', 'transaction_time' => '14:30',
            'payer_name' => 'Ana Souza', 'recipient_name' => 'Estudio', 'institution' => 'Banco Teste',
            'transaction_id' => 'E1234 5678 abcd', 'is_scheduled' => false, 'transaction_status' => 'completed',
            'confidence' => 0.95, 'warnings' => [],
        ], $overrides);
    }

    /** @return array{0: Company, 1: TattooQuote, 2: TattooAiConversation} */
    private function makeQuote(?Company $company = null, array $quoteAttributes = []): array
    {
        $company ??= $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $company->schedulingSetting()->updateOrCreate([], ['ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key']);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'fraud-'.Str::random(8),
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        $phone = '55119'.random_int(10000000, 99999999);
        $client = Client::factory()->forCompany($company)->create(['phone' => $phone]);
        $request = new TattooRequest(['client_id' => $client->id, 'description' => 'Rosa',
            'body_placement' => 'Braço', 'status' => 'accepted']);
        $request->company_id = $company->id;
        $request->save();
        $quote = new TattooQuote(array_merge(['version' => 1, 'price_type' => 'fixed', 'amount_min' => 400,
            'deposit_amount' => 100, 'message_snapshot' => 'Orçamento', 'accepted_at' => now()->subHours(2)], $quoteAttributes));
        $quote->company_id = $company->id;
        $quote->tattoo_request_id = $request->id;
        $quote->save();
        $conversation = TattooAiConversation::query()->create(['company_id' => $company->id,
            'company_whatsapp_instance_id' => $instance->id, 'phone_normalized' => $phone,
            'remote_jid' => $phone.'@s.whatsapp.net', 'client_id' => $client->id,
            'tattoo_request_id' => $request->id, 'status' => 'waiting_payment_receipt']);
        if (! FinancialAccount::query()->where('company_id', $company->id)->exists()) {
            FinancialAccount::factory()->forCompany($company)->create(['pix_key' => 'pix@example.com',
                'pix_recipient_name' => 'Estudio', 'is_default_receipt_account' => true]);
        }

        return [$company, $quote, $conversation];
    }

    private function analyze(TattooQuote $quote, TattooAiConversation $conversation, array $extraction): TattooPaymentReceipt
    {
        $this->extraction = $extraction;
        $path = 'agendaqui/'.$quote->company_id.'/tatuagem/comprovantes/'.Str::uuid().'.png';
        Storage::disk('local')->put($path, 'fake-image');
        $receipt = new TattooPaymentReceipt(['tattoo_request_id' => $quote->tattoo_request_id, 'tattoo_quote_id' => $quote->id,
            'tattoo_ai_conversation_id' => $conversation->id, 'whatsapp_message_id' => 'msg-'.Str::uuid(),
            'disk' => 'local', 'path' => $path, 'mime_type' => 'image/png', 'size_bytes' => 10]);
        $receipt->company_id = $quote->company_id;
        $receipt->save();

        return app(TattooReceiptService::class)->analyze($receipt);
    }

    public function test_clean_receipt_stays_compatible_and_stores_normalized_transaction_id(): void
    {
        [, $quote, $conversation] = $this->makeQuote();

        $receipt = $this->analyze($quote, $conversation, $this->cleanExtraction());

        $this->assertSame('compatible', $receipt->receipt_analysis_status);
        $this->assertSame([], $receipt->analysis['warnings']);
        $this->assertSame('E12345678ABCD', $receipt->transaction_id);
        $this->assertSame('receipt_received', $receipt->payment_status);
    }

    public function test_scheduled_pix_is_flagged_and_staff_notification_lists_the_warning(): void
    {
        [$company, $quote, $conversation] = $this->makeQuote();
        $admin = $this->createCompanyUser($company);

        $receipt = $this->analyze($quote, $conversation, $this->cleanExtraction([
            'is_scheduled' => true, 'transaction_status' => 'scheduled', 'transaction_date' => '2026-10-07', 'transaction_time' => '14:00',
        ]));

        $this->assertSame('inconsistent', $receipt->receipt_analysis_status);
        $this->assertSame(['Comprovante de PIX agendado, não é pagamento realizado.'], $receipt->analysis['warnings']);
        $notification = User::query()->findOrFail($admin->id)->notifications()->latest()->firstOrFail();
        $this->assertStringContainsString('Comprovante de PIX agendado', json_encode($notification->data, JSON_UNESCAPED_UNICODE));
    }

    public function test_future_transaction_date_is_treated_as_scheduled_even_without_flag(): void
    {
        [, $quote, $conversation] = $this->makeQuote();

        $tomorrow = $this->analyze($quote, $conversation, $this->cleanExtraction([
            'transaction_date' => '2026-10-08', 'transaction_time' => null, 'transaction_id' => 'FUT1',
        ]));
        $laterToday = $this->analyze($quote, $conversation, $this->cleanExtraction([
            'transaction_time' => '18:00', 'transaction_id' => 'FUT2',
        ]));
        $documentTypeScheduling = $this->analyze($quote, $conversation, $this->cleanExtraction([
            'document_type' => 'pix_scheduling', 'transaction_status' => 'Agendado', 'transaction_id' => 'FUT3',
        ]));

        foreach ([$tomorrow, $laterToday, $documentTypeScheduling] as $receipt) {
            $this->assertSame('inconsistent', $receipt->receipt_analysis_status);
            $this->assertContains('Comprovante de PIX agendado, não é pagamento realizado.', $receipt->analysis['warnings']);
        }
    }

    public function test_duplicate_transaction_id_is_flagged_for_same_quote_and_other_orders(): void
    {
        [$company, $quote, $conversation] = $this->makeQuote();
        $first = $this->analyze($quote, $conversation, $this->cleanExtraction());
        $this->assertSame('compatible', $first->receipt_analysis_status);

        $again = $this->analyze($quote, $conversation, $this->cleanExtraction(['transaction_id' => ' e1234 5678 ABCD ']));
        $this->assertSame('inconsistent', $again->receipt_analysis_status);
        $this->assertSame(['Comprovante já enviado antes.'], $again->analysis['warnings']);

        [, $otherQuote, $otherConversation] = $this->makeQuote($company);
        $otherOrder = $this->analyze($otherQuote, $otherConversation, $this->cleanExtraction());
        $this->assertSame(['Comprovante já enviado em outro pedido.'], $otherOrder->analysis['warnings']);

        [, $foreignQuote, $foreignConversation] = $this->makeQuote();
        $otherCompany = $this->analyze($foreignQuote, $foreignConversation, $this->cleanExtraction());
        $this->assertSame(['Comprovante já enviado em outro pedido.'], $otherCompany->analysis['warnings']);
    }

    public function test_receipt_dated_before_quote_acceptance_is_flagged(): void
    {
        [, $quote, $conversation] = $this->makeQuote();
        $warning = 'Data do comprovante anterior ao aceite do orçamento.';

        $earlierToday = $this->analyze($quote, $conversation, $this->cleanExtraction(['transaction_time' => '12:00', 'transaction_id' => 'OLD1']));
        $yesterday = $this->analyze($quote, $conversation, $this->cleanExtraction([
            'transaction_date' => '2026-10-06', 'transaction_time' => null, 'transaction_id' => 'OLD2',
        ]));
        $sameDayNoTime = $this->analyze($quote, $conversation, $this->cleanExtraction(['transaction_time' => null, 'transaction_id' => 'OLD3']));
        $withinTolerance = $this->analyze($quote, $conversation, $this->cleanExtraction(['transaction_time' => '12:58', 'transaction_id' => 'OLD4']));

        $this->assertSame([$warning], $earlierToday->analysis['warnings']);
        $this->assertSame('inconsistent', $earlierToday->receipt_analysis_status);
        $this->assertSame([$warning], $yesterday->analysis['warnings']);
        $this->assertSame('compatible', $sameDayNoTime->receipt_analysis_status);
        $this->assertSame('compatible', $withinTolerance->receipt_analysis_status);
    }

    public function test_acceptance_falls_back_to_sent_at_when_quote_has_no_acceptance(): void
    {
        [, $quote, $conversation] = $this->makeQuote(quoteAttributes: ['accepted_at' => null, 'sent_at' => now()->subHour()]);

        $receipt = $this->analyze($quote, $conversation, $this->cleanExtraction(['transaction_time' => '13:30']));

        $this->assertSame(['Data do comprovante anterior ao aceite do orçamento.'], $receipt->analysis['warnings']);
    }
}
