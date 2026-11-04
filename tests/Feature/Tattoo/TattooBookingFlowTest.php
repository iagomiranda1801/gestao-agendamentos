<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Jobs\NotifyTattooHandoffJob;
use App\Jobs\NotifyTattooReceiptJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\FinancialAccount;
use App\Models\Professional;
use App\Models\ScheduleBlock;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Models\TattooPaymentReceipt;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Services\Tattoo\TattooAIConversationService;
use App\Services\Tattoo\TattooReceiptService;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

/** Etapas 4 e 5: escolher dia/horário antes do sinal e agendar só com o comprovante aprovado. */
class TattooBookingFlowTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private string $phone = '5511988888888';

    private string $artistPhone = '5511977776666';

    private $company;

    private $instance;

    private Professional $professional;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        // Segunda, 09/11/2026 07:00 (São Paulo).
        $this->travelTo(CarbonImmutable::parse('2026-11-09 07:00', 'America/Sao_Paulo'));
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio, 'name' => 'GODS TATOO',
            'timezone' => 'America/Sao_Paulo']);
        $this->professional = Professional::factory()->forCompany($company)->bookable()->active()->create(['name' => 'Arrenius Silva']);
        $this->seedStandardScheduling($company, $this->professional);
        $company->schedulingSetting()->updateOrCreate([], ['ai_provider' => 'gemini', 'ai_model' => 'gemini-2.5-flash',
            'ai_api_key' => 'test-key', 'tattoo_ai_enabled' => true, 'slot_interval_minutes' => 60,
            'minimum_advance_minutes' => 0, 'maximum_advance_days' => 30, 'tattoo_artist_notify_phone' => $this->artistPhone]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'booking-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key', 'filesystems.tattoo_disk' => 'local']);
        Queue::fake([NotifyTattooHandoffJob::class, NotifyTattooReceiptJob::class]);
        Storage::fake('local');
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
        FinancialAccount::factory()->forCompany($company)->create(['pix_key' => 'pix@estudio.test',
            'pix_recipient_name' => 'Estudio', 'is_default_receipt_account' => true]);
        $this->company = $company->fresh();
        $this->instance = $instance;
        $this->client = Client::factory()->forCompany($company)->create(['name' => 'Bia Souza', 'phone' => '(11) 98888-8888']);
    }

    private function send(string $text, string $id, ?string $mime = null): string
    {
        $paused = app(WhatsAppHumanTakeover::class)->isPaused($this->instance->instance_name, $this->phone);
        app(TattooAIConversationService::class)->handle($this->company, $this->instance, $this->phone.'@s.whatsapp.net',
            $this->phone, $text, $id, $mime, $paused, null);

        return (string) TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('body');
    }

    private function quote(float $deposit = 100, bool $accepted = false): TattooRequest
    {
        $request = new TattooRequest(['client_id' => $this->client->id, 'description' => 'Rosa fineline',
            'body_placement' => 'Antebraço', 'source' => 'manual', 'status' => $accepted ? 'accepted' : 'quote_sent']);
        $request->company_id = $this->company->id;
        $request->save();
        $quote = new TattooQuote();
        $quote->forceFill(['company_id' => $this->company->id, 'tattoo_request_id' => $request->id, 'version' => 1,
            'price_type' => 'fixed', 'amount_min' => 450, 'sessions' => 1, 'deposit_amount' => $deposit, 'minutes_per_session' => 60,
            'message_snapshot' => 'Orçamento: R$ 450', 'sent_at' => now()->subHour(), 'accepted_at' => $accepted ? now() : null])->save();
        TattooAiConversation::query()->create(['company_id' => $this->company->id, 'company_whatsapp_instance_id' => $this->instance->id,
            'phone_normalized' => $this->phone, 'remote_jid' => $this->phone.'@s.whatsapp.net', 'client_id' => $this->client->id,
            'tattoo_request_id' => $request->id, 'status' => $accepted ? 'payment_confirmed' : 'waiting_professional_quote']);

        return $request;
    }

    private function conversation(): TattooAiConversation
    {
        return TattooAiConversation::query()->firstOrFail();
    }

    private function appointmentAt(string $local): Appointment
    {
        $start = CompanyDateTime::localToUtc($this->company, CarbonImmutable::parse($local, 'America/Sao_Paulo'));

        return Appointment::factory()->forCompany($this->company)->create(['professional_id' => $this->professional->id,
            'client_id' => Client::factory()->forCompany($this->company)->create()->id,
            'start_at' => $start, 'end_at' => $start->addHour(), 'status' => 'confirmed']);
    }

    /** @return list<string> */
    private function textsTo(string $phone): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), 'sendText') && str_contains(json_encode($r->data()), $phone))
            ->map(fn ($r) => (string) ($r->data()['text'] ?? json_encode($r->data(), JSON_UNESCAPED_UNICODE)))->values()->all();
    }

    private function receipt(TattooRequest $request, string $status = 'receipt_received'): TattooPaymentReceipt
    {
        $receipt = new TattooPaymentReceipt(['tattoo_request_id' => $request->id, 'tattoo_quote_id' => $request->quotes()->first()->id,
            'tattoo_ai_conversation_id' => $this->conversation()->id, 'whatsapp_message_id' => 'pix-'.uniqid(),
            'disk' => 'local', 'path' => 'x.png', 'mime_type' => 'image/png', 'size_bytes' => 10, 'payment_status' => $status]);
        $receipt->company_id = $this->company->id;
        $receipt->save();

        return $receipt;
    }

    private function chooseWednesdayTen(): void
    {
        $this->send('aceito', 'a1');
        $this->send('quarta', 'a2');
        $this->send('2', 'a3');
    }

    public function test_accept_lists_only_days_with_free_slots(): void
    {
        $this->quote();
        ScheduleBlock::factory()->forProfessional($this->professional)->create([
            'start_at' => CompanyDateTime::localToUtc($this->company, CarbonImmutable::parse('2026-11-10 00:00', 'America/Sao_Paulo')),
            'end_at' => CompanyDateTime::localToUtc($this->company, CarbonImmutable::parse('2026-11-10 23:59', 'America/Sao_Paulo'))]);

        $reply = $this->send('aceito', 'a1');

        $this->assertStringContainsString("1. Seg 09/11\n2. Qua 11/11", $reply);
        $this->assertStringNotContainsString('10/11', $reply);
        $this->assertStringNotContainsString('15/11', $reply); // domingo sem jornada
        $this->assertStringContainsString('7. Ter 17/11', $reply);
        $this->assertStringNotContainsString('Chave PIX', $reply);
        $this->assertSame('offering_slots', $this->conversation()->status);
        $this->assertDatabaseCount('appointments', 0);
    }

    public function test_days_respect_minimum_notice_and_maximum_advance(): void
    {
        $this->company->schedulingSetting->update(['minimum_advance_minutes' => 11 * 60, 'maximum_advance_days' => 2]);
        $this->quote();

        $reply = $this->send('aceito', 'a1');

        $this->assertStringNotContainsString('09/11', $reply);
        $this->assertStringContainsString("1. Ter 10/11\n2. Qua 11/11", $reply);
        $this->assertStringNotContainsString('12/11', $reply);
    }

    public function test_picking_day_and_time_stores_slot_and_sends_pix_without_booking(): void
    {
        $this->quote();
        $this->appointmentAt('2026-11-11 09:00');
        $this->send('aceito', 'a1');

        $times = $this->send('quarta', 'a2');
        $this->assertStringContainsString('Qua 11/11', $times);
        $this->assertStringNotContainsString('09:00', $times);
        $this->assertStringContainsString('1. 10:00', $times);

        $reply = $this->send('1', 'a3');
        $this->assertStringContainsString('Qua 11/11 às 10:00', $reply);
        $this->assertStringContainsString('pix@estudio.test', $reply);
        $this->assertStringContainsString('R$ 100,00', $reply);
        $this->assertStringContainsString('só fica reservado depois', $reply);
        $this->assertSame('2026-11-11 10:00', $this->conversation()->collected_data['_schedule']['slot']);
        $this->assertSame('waiting_payment_receipt', $this->conversation()->status);
        $this->assertDatabaseCount('appointments', 1); // só o que já existia
    }

    public function test_day_by_number_or_date_and_outro_dia_returns_to_days(): void
    {
        $this->quote();
        $this->send('aceito', 'a1');
        $this->assertStringContainsString('Ter 10/11', $this->send('2', 'a2'));
        $this->assertStringContainsString('Esses são os próximos dias', $this->send('outro dia', 'a3'));
        $this->assertStringContainsString('Qui 12/11', $this->send('12/11', 'a4'));
        $this->assertStringContainsString('Não entendi o horário', $this->send('pode ser', 'a5'));
    }

    public function test_scheduling_keyword_on_sent_quote_shows_days(): void
    {
        $request = $this->quote();

        $reply = $this->send('quero agendar', 'k1');

        $this->assertStringContainsString('1. Seg 09/11', $reply);
        $this->send('1', 'k2');
        $this->assertStringContainsString('Chave PIX', $this->send('1', 'k3'));
        $this->assertNotNull($request->quotes()->first()->accepted_at);
        $this->assertSame('accepted', $request->fresh()->status);
    }

    public function test_approved_receipt_books_slot_and_notifies_artist_and_customer(): void
    {
        $request = $this->quote();
        $this->chooseWednesdayTen();
        $receipt = $this->receipt($request);

        app(TattooReceiptService::class)->review($receipt, $this->createCompanyUser($this->company), true);

        $appointment = Appointment::query()->where('reference_key', 'tattoo-ai-'.$request->id)->firstOrFail();
        $this->assertSame('2026-11-11 10:00', CompanyDateTime::utcToLocal($this->company, CarbonImmutable::parse($appointment->start_at))->format('Y-m-d H:i'));
        $this->assertSame('confirmed', $appointment->status->value);
        $this->assertSame('booked', $request->fresh()->status);
        $this->assertSame('converted_to_appointment', $this->conversation()->status);
        $artist = implode("\n", $this->textsTo($this->artistPhone));
        $this->assertStringContainsString('Agendamento confirmado: Bia Souza dia 11/11 às 10:00, sinal R$ 100,00 pago ✅', $artist);
        $this->assertStringContainsString('Fechado! ✅', implode("\n", $this->textsTo($this->phone)));
    }

    public function test_approved_receipt_with_taken_slot_offers_new_days_without_new_pix(): void
    {
        $request = $this->quote();
        $this->chooseWednesdayTen();
        $this->appointmentAt('2026-11-11 10:00'); // outro cliente pagou antes
        $receipt = $this->receipt($request);

        app(TattooReceiptService::class)->review($receipt, $this->createCompanyUser($this->company), true);

        $this->assertNull($request->fresh()->appointment_id);
        $message = (string) TattooAiMessage::query()->where('provider_message_id', 'payment-confirmed:'.$receipt->id)->value('body');
        $this->assertStringContainsString('acabou de ser ocupado', $message);
        $this->assertStringContainsString('1. Seg 09/11', $message);
        $this->assertStringNotContainsString('Chave PIX', $message);
        // Escolhe de novo e já agenda, sem novo PIX.
        $this->send('1', 'r1');
        $this->assertStringContainsString('Fechado! ✅', $this->send('1', 'r2'));
        $this->assertNotNull($request->fresh()->appointment_id);
    }

    public function test_approved_receipt_before_slot_asks_for_day(): void
    {
        $request = $this->quote(100, true);
        $this->conversation()->update(['status' => 'receipt_received']);
        $receipt = $this->receipt($request);

        app(TattooReceiptService::class)->review($receipt, $this->createCompanyUser($this->company), true);

        $message = (string) TattooAiMessage::query()->where('provider_message_id', 'payment-confirmed:'.$receipt->id)->value('body');
        $this->assertStringContainsString('Sinal confirmado ✅', $message);
        $this->assertStringContainsString('1. Seg 09/11', $message);
        $this->assertNull($request->fresh()->appointment_id);
    }

    public function test_problem_receipt_flags_and_notifies_artist_and_asks_for_day(): void
    {
        $request = $this->quote(100, true);
        $this->conversation()->update(['status' => 'waiting_payment_receipt']);
        $reply = $this->send('', 'img-1', 'image/png');

        $receipt = TattooPaymentReceipt::query()->firstOrFail();
        $this->assertSame('inconsistent', $receipt->receipt_analysis_status);
        $this->assertSame('receipt_received', $receipt->payment_status);
        $this->assertStringContainsString('Comprovante com problema: Bia Souza', implode("\n", $this->textsTo($this->artistPhone)));
        $this->assertStringContainsString('1. Seg 09/11', $reply);
        $this->assertStringContainsString('Anotado: Seg 09/11', $this->send('1', 'img-2').$this->send('1', 'img-3'));
        $this->assertNull($request->fresh()->appointment_id);
    }

    public function test_no_deposit_quote_books_directly_and_legacy_format_still_works(): void
    {
        $this->createCompanyUser($this->company);
        $request = $this->quote(0);
        $this->assertStringContainsString('1. Seg 09/11', $this->send('aceito', 'z1'));
        $this->assertSame('ready_to_schedule', $this->conversation()->status);

        $reply = $this->send('2026-11-10 11:00', 'z2');

        $this->assertStringContainsString('Fechado! ✅ Horário garantido: Ter 10/11 às 11:00', $reply);
        $this->assertNotNull($request->fresh()->appointment_id);
        $artist = implode("\n", $this->textsTo($this->artistPhone));
        $this->assertStringContainsString('Agendamento confirmado: Bia Souza dia 10/11 às 11:00 ✅', $artist);
    }
}
