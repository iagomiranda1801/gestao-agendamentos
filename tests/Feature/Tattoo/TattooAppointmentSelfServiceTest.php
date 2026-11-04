<?php

namespace Tests\Feature\Tattoo;

use App\Enums\AppointmentStatus;
use App\Enums\CompanyProfile;
use App\Jobs\NotifyTattooHandoffJob;
use App\Jobs\NotifyTattooReceiptJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\Professional;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Services\Tattoo\TattooAIConversationService;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

/** Etapa 6: cliente remarca ou cancela sozinho o horário marcado. */
class TattooAppointmentSelfServiceTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private string $phone = '5511988888888';

    private string $artistPhone = '5511977776666';

    private $company;

    private $instance;

    private Professional $professional;

    private Client $client;

    private Appointment $appointment;

    private TattooQuote $quote;

    protected function setUp(): void
    {
        parent::setUp();
        // Segunda, 09/11/2026 07:00 (São Paulo).
        $this->travelTo(CarbonImmutable::parse('2026-11-09 07:00', 'America/Sao_Paulo'));
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio, 'name' => 'GODS TATOO',
            'timezone' => 'America/Sao_Paulo']);
        $this->createCompanyUser($company);
        $this->professional = Professional::factory()->forCompany($company)->bookable()->active()->create(['name' => 'Gustavo Lima']);
        $this->seedStandardScheduling($company, $this->professional);
        $company->schedulingSetting()->updateOrCreate([], ['ai_provider' => 'gemini', 'ai_model' => 'gemini-2.5-flash',
            'ai_api_key' => 'test-key', 'tattoo_ai_enabled' => true, 'slot_interval_minutes' => 60,
            'minimum_advance_minutes' => 0, 'maximum_advance_days' => 30, 'tattoo_artist_notify_phone' => $this->artistPhone]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'self-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key']);
        Queue::fake([NotifyTattooHandoffJob::class, NotifyTattooReceiptJob::class]);
        Http::fake(fn () => Http::response(['key' => ['id' => 'sent']]));
        $this->company = $company->fresh();
        $this->instance = $instance;
        $this->client = Client::factory()->forCompany($company)->create(['name' => 'Bia Souza', 'phone' => '(11) 98888-8888']);

        // Quarta 11/11 14:00, sinal de R$ 100 já pago.
        $start = CompanyDateTime::localToUtc($this->company, CarbonImmutable::parse('2026-11-11 14:00', 'America/Sao_Paulo'));
        $this->appointment = Appointment::factory()->forCompany($this->company)->create(['professional_id' => $this->professional->id,
            'client_id' => $this->client->id, 'start_at' => $start, 'end_at' => $start->addHour(), 'status' => 'confirmed',
            'duration_minutes_snapshot' => 60, 'service_id' => null, 'is_tattoo' => true]);
        $request = new TattooRequest(['client_id' => $this->client->id, 'description' => 'Rosa fineline',
            'body_placement' => 'Antebraço', 'source' => 'manual', 'status' => 'booked']);
        $request->company_id = $this->company->id;
        $request->appointment_id = $this->appointment->id;
        $request->save();
        $this->quote = new TattooQuote();
        $this->quote->forceFill(['company_id' => $this->company->id, 'tattoo_request_id' => $request->id, 'version' => 1,
            'price_type' => 'fixed', 'amount_min' => 450, 'sessions' => 1, 'deposit_amount' => 100, 'minutes_per_session' => 60,
            'message_snapshot' => 'Orçamento: R$ 450', 'sent_at' => now()->subDay(), 'accepted_at' => now()->subDay()])->save();
        TattooAiConversation::query()->create(['company_id' => $this->company->id, 'company_whatsapp_instance_id' => $this->instance->id,
            'phone_normalized' => $this->phone, 'remote_jid' => $this->phone.'@s.whatsapp.net', 'client_id' => $this->client->id,
            'tattoo_request_id' => $request->id, 'status' => 'converted_to_appointment']);
    }

    private function settings(array $values): void
    {
        $this->company->schedulingSetting->update($values);
        $this->company = $this->company->fresh();
    }

    private function send(string $text, string $id): string
    {
        $paused = app(WhatsAppHumanTakeover::class)->isPaused($this->instance->instance_name, $this->phone);
        app(TattooAIConversationService::class)->handle($this->company, $this->instance, $this->phone.'@s.whatsapp.net',
            $this->phone, $text, $id, null, $paused, null);

        return (string) TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('body');
    }

    /** @return list<string> */
    private function textsTo(string $phone): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), 'sendText') && str_contains(json_encode($r->data()), $phone))
            ->map(fn ($r) => (string) ($r->data()['text'] ?? ''))->values()->all();
    }

    private function conversation(): TattooAiConversation
    {
        return TattooAiConversation::query()->firstOrFail();
    }

    private function localStart(): string
    {
        return CompanyDateTime::utcToLocal($this->company, CarbonImmutable::parse($this->appointment->fresh()->start_at))->format('Y-m-d H:i');
    }

    public function test_reschedule_moves_appointment_keeping_deposit(): void
    {
        $this->assertStringContainsString('1 Remarcar', $this->send('oi', 'r1'));
        $days = $this->send('1', 'r2');
        $this->assertStringContainsString('próximos dias com horário livre', $days);
        $this->assertFalse((bool) $this->conversation()->human_takeover);

        $times = $this->send('1', 'r3');
        $this->assertStringContainsString('Horários livres', $times);
        $state = $this->conversation()->collected_data['_manage'];
        $slot = $state['date'].' '.$state['times'][0];

        $reply = $this->send('1', 'r4');

        $this->assertStringContainsString('remarcado', $reply);
        $this->assertStringContainsString('continua valendo', $reply);
        $this->assertSame($slot, $this->localStart());
        $this->assertSame(AppointmentStatus::Confirmed, $this->appointment->fresh()->status);
        $this->assertArrayNotHasKey('_manage', $this->conversation()->collected_data);
        $this->assertTrue(collect($this->textsTo($this->artistPhone))->contains(fn ($t) => str_contains($t, 'remarcou')));
        $this->assertStringNotContainsString('pix@', $reply);
    }

    public function test_own_slot_is_free_but_not_offered_again(): void
    {
        $this->send('oi', 's1');
        $this->send('1', 's2');
        $this->send('11/11', 's3');
        $state = $this->conversation()->collected_data['_manage'];

        $this->assertSame('2026-11-11', $state['date']);
        $this->assertNotContains('14:00', $state['times']);
        $this->assertNotEmpty($state['times']);
    }

    public function test_reschedule_offers_new_days_when_slot_was_taken(): void
    {
        $this->send('oi', 't1');
        $this->send('1', 't2');
        $this->send('1', 't3');
        $state = $this->conversation()->collected_data['_manage'];
        $taken = CompanyDateTime::localToUtc($this->company, CarbonImmutable::parse($state['date'].' '.$state['times'][0], 'America/Sao_Paulo'));
        Appointment::factory()->forCompany($this->company)->create(['professional_id' => $this->professional->id,
            'client_id' => Client::factory()->forCompany($this->company)->create()->id,
            'start_at' => $taken, 'end_at' => $taken->addHour(), 'status' => 'confirmed']);

        $reply = $this->send('1', 't4');

        $this->assertStringContainsString('acabou de ser ocupado', $reply);
        $this->assertStringContainsString('próximos dias', $reply);
        $this->assertSame('2026-11-11 14:00', $this->localStart());
    }

    public function test_cancel_retains_deposit_by_default(): void
    {
        $this->send('oi', 'c1');
        $confirm = $this->send('2', 'c2');
        $this->assertStringContainsString('Certeza que quer cancelar', $confirm);
        $this->assertStringContainsString('retido', $confirm);
        $this->assertSame(AppointmentStatus::Confirmed, $this->appointment->fresh()->status);

        $reply = $this->send('1', 'c3');

        $this->assertStringContainsString('cancelei', $reply);
        $this->assertStringContainsString('retido', $reply);
        $this->assertSame(AppointmentStatus::Cancelled, $this->appointment->fresh()->status);
        $this->assertSame('retained', $this->quote->fresh()->deposit_outcome);
        $this->assertTrue(collect($this->textsTo($this->artistPhone))->contains(fn ($t) => str_contains($t, 'cancelou') && str_contains($t, 'retido')));
    }

    public function test_cancel_refunds_when_policy_allows_before_cutoff(): void
    {
        $this->settings(['tattoo_cancel_deposit_policy' => 'reembolsar', 'tattoo_refund_cutoff_hours' => 24]);
        $this->send('oi', 'd1');
        $this->send('2', 'd2');

        $reply = $this->send('sim', 'd3');

        $this->assertStringContainsString('devolvido', $reply);
        $this->assertSame('refund_pending', $this->quote->fresh()->deposit_outcome);
        $this->assertNotNull($this->quote->fresh()->deposit_outcome_at);
        $this->assertTrue(collect($this->textsTo($this->artistPhone))->contains(fn ($t) => str_contains($t, 'REEMBOLSAR')
            && str_contains($t, 'manualmente')));
    }

    public function test_cancel_after_refund_cutoff_retains_deposit(): void
    {
        $this->settings(['tattoo_cancel_deposit_policy' => 'reembolsar', 'tattoo_refund_cutoff_hours' => 72]);
        $this->send('oi', 'e1');
        $this->send('2', 'e2');
        $this->send('1', 'e3');

        $this->assertSame('retained', $this->quote->fresh()->deposit_outcome);
    }

    public function test_answering_no_keeps_appointment(): void
    {
        $this->send('oi', 'n1');
        $this->send('2', 'n2');

        $reply = $this->send('2', 'n3');

        $this->assertStringContainsString('continua de pé', $reply);
        $this->assertSame(AppointmentStatus::Confirmed, $this->appointment->fresh()->status);
        $this->assertNull($this->quote->fresh()->deposit_outcome);
    }

    public function test_short_notice_hands_over_to_artist(): void
    {
        $this->settings(['tattoo_self_service_min_hours' => 72]);
        $this->send('oi', 'h1');

        $reply = $this->send('1', 'h2');

        $this->assertStringContainsString('remarcar', $reply);
        $this->assertTrue((bool) $this->conversation()->human_takeover);
        $this->assertSame('2026-11-11 14:00', $this->localStart());
        Queue::assertPushed(NotifyTattooHandoffJob::class);
    }

    public function test_talk_to_artist_still_hands_over(): void
    {
        $this->send('oi', 'g1');

        $this->send('3', 'g2');

        $this->assertTrue((bool) $this->conversation()->human_takeover);
        Queue::assertPushed(NotifyTattooHandoffJob::class);
    }
}
