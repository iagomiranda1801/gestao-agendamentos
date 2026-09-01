<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\WhatsAppAutomationType;
use App\Enums\WhatsAppCampaignAudience;
use App\Enums\WhatsAppOutboundKind;
use App\Jobs\SendWhatsAppAppointmentConfirmationJob;
use App\Jobs\SendWhatsAppAutomationJob;
use App\Models\Appointment;
use App\Models\WhatsAppAutomationSend;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Automations\WhatsAppAutomationService;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundGate;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class WhatsAppOutboundGateTest extends TestCase
{
    use CreatesSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.instance' => 'default',
            'services.evolution.outbound.min_interval_seconds' => 30,
            'services.evolution.outbound.max_interval_seconds' => 30,
            'services.evolution.outbound.jitter_seconds' => 0,
            'services.evolution.outbound.daily_limit' => 80,
            'services.evolution.outbound.circuit_failures' => 5,
            'services.evolution.outbound.circuit_pause_minutes' => 120,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-01 13:00:00', 'UTC'));
    }

    public function test_burst_reserves_slots_thirty_seconds_apart(): void
    {
        $company = $this->createCompany();
        $gate = app(WhatsAppOutboundGate::class);

        $first = $gate->reserve($company, WhatsAppOutboundKind::Reminder);
        $second = $gate->reserve($company, WhatsAppOutboundKind::Reminder);
        $third = $gate->reserve($company, WhatsAppOutboundKind::Marketing);

        $this->assertTrue($first->allowed);
        $this->assertTrue($second->allowed);
        $this->assertTrue($third->allowed);
        $now = now()->getTimestamp();
        $this->assertEqualsWithDelta($now, $first->availableAt?->getTimestamp() ?? 0, 1);
        $this->assertEqualsWithDelta($now + 30, $second->availableAt?->getTimestamp() ?? 0, 1);
        $this->assertEqualsWithDelta($now + 60, $third->availableAt?->getTimestamp() ?? 0, 1);
    }

    public function test_ten_inline_jobs_do_not_hit_evolution_in_a_burst(): void
    {
        Http::fake([
            'evolution.test/*' => Http::response(['status' => 'SENT'], 200),
        ]);

        $setup = $this->createBookableSetup();
        $sends = [];

        for ($i = 0; $i < 10; $i++) {
            $send = new WhatsAppAutomationSend([
                'type' => 'reminder',
                'phone' => '1199999000'.$i,
                'message_snapshot' => 'Oi',
                'status' => 'pending',
            ]);
            $send->company()->associate($setup['company']);
            $send->automation()->associate(
                app(WhatsAppAutomationService::class)->getOrCreate($setup['company'], WhatsAppAutomationType::Reminder),
            );
            $send->save();
            $sends[] = $send;
        }

        foreach ($sends as $send) {
            (new SendWhatsAppAutomationJob($send->getKey()))->handle(app(WhatsAppAutomationService::class));
        }

        Http::assertSentCount(1);
        $this->assertSame(1, WhatsAppAutomationSend::query()->where('status', 'sent')->count());
        $this->assertSame(9, WhatsAppAutomationSend::query()->where('status', 'pending')->count());
    }

    public function test_campaign_interval_below_thirty_is_raised(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);

        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Rápida',
            'audience_type' => WhatsAppCampaignAudience::OptedInActiveClients->value,
            'message_template' => 'Oi {nome}',
            'send_interval_seconds' => 10,
        ]);

        $this->assertSame(30, (int) $campaign->send_interval_seconds);
    }

    public function test_daily_cap_blocks_marketing_but_allows_confirmation(): void
    {
        Http::fake([
            'evolution.test/*' => Http::response(['status' => 'SENT'], 200),
        ]);

        $setup = $this->createBookableSetup();
        $this->enablePublicWhatsApp($setup['company']);
        $gate = app(WhatsAppOutboundGate::class);

        config(['services.evolution.outbound.daily_limit' => 2]);

        $gate->recordSuccess($setup['company']);
        $gate->recordSuccess($setup['company']);

        $this->assertFalse($gate->allowsMarketing($setup['company']));
        $this->assertFalse($gate->reserve($setup['company'], WhatsAppOutboundKind::Marketing)->allowed);
        $this->assertFalse($gate->reserve($setup['company'], WhatsAppOutboundKind::Reminder)->allowed);

        $appointment = Appointment::factory()
            ->forCompany($setup['company'])
            ->confirmed()
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'client_phone_snapshot' => '11999990001',
                'created_by' => $setup['admin']->getKey(),
            ]);

        (new SendWhatsAppAppointmentConfirmationJob($appointment->getKey()))->handle(
            app(EvolutionApiClient::class),
            app(WhatsAppConfirmationMessageBuilder::class),
        );

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/message/sendText/'));
    }

    public function test_circuit_breaker_pauses_marketing(): void
    {
        $company = $this->createCompany();
        $gate = app(WhatsAppOutboundGate::class);

        for ($i = 0; $i < 5; $i++) {
            $gate->recordFailure($company);
        }

        $this->assertFalse($gate->allowsMarketing($company));
        $this->assertFalse($gate->reserve($company, WhatsAppOutboundKind::Reminder)->allowed);
        $this->assertTrue($gate->reserve($company, WhatsAppOutboundKind::Confirmation)->allowed);
    }

    protected function enablePublicWhatsApp(mixed $company): void
    {
        app(CompanySchedulingSettingService::class)->update($company, [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'loja-1',
            'whatsapp_sender_phone' => '11999998888',
        ]);
    }
}
