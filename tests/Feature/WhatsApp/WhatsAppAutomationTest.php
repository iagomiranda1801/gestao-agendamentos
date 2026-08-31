<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\WhatsAppAutomationSendStatus;
use App\Enums\WhatsAppAutomationType;
use App\Enums\WhatsAppCampaignAudience;
use App\Jobs\SendWhatsAppAfterSalesJob;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Client;
use App\Services\Client\ClientService;
use App\Services\Company\CompanyModuleService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Automations\WhatsAppAutomationService;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use App\Support\CompanyTerminology;
use App\Support\VehiclePlate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class WhatsAppAutomationTest extends TestCase
{
    use CreatesSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.instance' => 'default',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['key' => ['id' => 'ok'], 'status' => 'SENT'], 200),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-09-01 13:00:00', 'UTC'));
    }

    public function test_reminder_sends_for_confirmed_appointment_inside_window(): void
    {
        $setup = $this->createBookableSetup();
        $this->enableOperationalWhatsApp($setup['company']);
        $setup['client']->update(['phone' => '(11) 99999-0001']);

        $this->enableAutomation($setup['company'], WhatsAppAutomationType::Reminder, [
            'is_enabled' => true,
            'delay_value' => 24,
        ]);

        Appointment::factory()
            ->forCompany($setup['company'])
            ->confirmed()
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'start_at' => now()->addHours(10),
                'end_at' => now()->addHours(11),
                'client_name_snapshot' => $setup['client']->name,
                'client_phone_snapshot' => $setup['client']->phone,
                'created_by' => $setup['admin']->getKey(),
            ]);

        $queued = app(WhatsAppAutomationService::class)->processCompany($setup['company']);

        $this->assertSame(1, $queued);
        $this->assertDatabaseHas('whatsapp_automation_sends', [
            'company_id' => $setup['company']->getKey(),
            'client_id' => $setup['client']->getKey(),
            'type' => WhatsAppAutomationType::Reminder->value,
            'status' => WhatsAppAutomationSendStatus::Sent->value,
        ]);
    }

    public function test_reminder_does_not_send_when_operational_whatsapp_is_disabled(): void
    {
        $setup = $this->createBookableSetup();
        $setup['client']->update(['phone' => '(11) 99999-0001']);

        $this->enableAutomation($setup['company'], WhatsAppAutomationType::Reminder, [
            'is_enabled' => true,
            'delay_value' => 24,
        ]);

        Appointment::factory()
            ->forCompany($setup['company'])
            ->confirmed()
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'start_at' => now()->addHours(10),
                'end_at' => now()->addHours(11),
                'created_by' => $setup['admin']->getKey(),
            ]);

        $queued = app(WhatsAppAutomationService::class)->processCompany($setup['company']);

        $this->assertSame(0, $queued);
        $this->assertDatabaseCount('whatsapp_automation_sends', 0);
    }

    public function test_win_back_requires_marketing_opt_in(): void
    {
        $setup = $this->createInactiveVisitSetup(optIn: false);

        $this->enableAutomation($setup['company'], WhatsAppAutomationType::WinBack, [
            'is_enabled' => true,
            'delay_value' => 30,
            'cooldown_days' => 30,
        ]);

        $queued = app(WhatsAppAutomationService::class)->processCompany($setup['company']);

        $this->assertSame(0, $queued);
    }

    public function test_win_back_skips_client_with_future_appointment(): void
    {
        $setup = $this->createInactiveVisitSetup(optIn: true);

        Appointment::factory()
            ->forCompany($setup['company'])
            ->confirmed()
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'start_at' => now()->addDays(2),
                'end_at' => now()->addDays(2)->addHour(),
                'created_by' => $setup['admin']->getKey(),
            ]);

        $this->enableAutomation($setup['company'], WhatsAppAutomationType::WinBack, [
            'is_enabled' => true,
            'delay_value' => 30,
            'cooldown_days' => 30,
        ]);

        $queued = app(WhatsAppAutomationService::class)->processCompany($setup['company']);

        $this->assertSame(0, $queued);
    }

    public function test_win_back_sends_to_opted_in_inactive_client(): void
    {
        $setup = $this->createInactiveVisitSetup(optIn: true);

        $this->enableAutomation($setup['company'], WhatsAppAutomationType::WinBack, [
            'is_enabled' => true,
            'delay_value' => 30,
            'cooldown_days' => 30,
        ]);

        $queued = app(WhatsAppAutomationService::class)->processCompany($setup['company']);

        $this->assertSame(1, $queued);
        $this->assertDatabaseHas('whatsapp_automation_sends', [
            'client_id' => $setup['client']->getKey(),
            'type' => WhatsAppAutomationType::WinBack->value,
            'status' => WhatsAppAutomationSendStatus::Sent->value,
        ]);
    }

    public function test_after_sales_is_queued_when_enabled(): void
    {
        Queue::fake();

        $setup = $this->createBookableSetup();
        $this->enableOperationalWhatsApp($setup['company']);
        $setup['client']->update(['phone' => '(11) 99999-0001']);

        $this->enableAutomation($setup['company'], WhatsAppAutomationType::AfterSales, [
            'is_enabled' => true,
            'delay_value' => 2,
        ]);

        $appointment = Appointment::factory()
            ->forCompany($setup['company'])
            ->completed()
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'start_at' => now()->subHour(),
                'end_at' => now(),
                'created_by' => $setup['admin']->getKey(),
            ]);

        $attendance = Attendance::factory()
            ->forAppointment($appointment)
            ->create([
                'completed_by' => $setup['admin']->getKey(),
                'completed_at' => now(),
            ]);

        app(WhatsAppAutomationService::class)->queueAfterSalesIfEnabled($attendance);

        Queue::assertPushed(SendWhatsAppAfterSalesJob::class, function (SendWhatsAppAfterSalesJob $job) use ($attendance): bool {
            return $job->attendanceId === $attendance->getKey();
        });
    }

    public function test_after_sales_skips_when_client_already_rebooked(): void
    {
        $setup = $this->createBookableSetup();
        $this->enableOperationalWhatsApp($setup['company']);
        $setup['client']->update(['phone' => '(11) 99999-0001']);

        $this->enableAutomation($setup['company'], WhatsAppAutomationType::AfterSales, [
            'is_enabled' => true,
            'delay_value' => 2,
        ]);

        $past = Appointment::factory()
            ->forCompany($setup['company'])
            ->completed()
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'start_at' => now()->subDays(1),
                'end_at' => now()->subDays(1)->addHour(),
                'created_by' => $setup['admin']->getKey(),
            ]);

        $attendance = Attendance::factory()
            ->forAppointment($past)
            ->create([
                'completed_by' => $setup['admin']->getKey(),
                'completed_at' => now()->subHours(3),
            ]);

        Appointment::factory()
            ->forCompany($setup['company'])
            ->confirmed()
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'start_at' => now()->addDays(3),
                'end_at' => now()->addDays(3)->addHour(),
                'created_by' => $setup['admin']->getKey(),
            ]);

        $sent = app(WhatsAppAutomationService::class)->sendAfterSales($attendance);

        $this->assertFalse($sent);
        $this->assertDatabaseCount('whatsapp_automation_sends', 0);
    }

    public function test_campaign_inactive_audience_uses_last_visit_and_opt_in(): void
    {
        $setup = $this->createInactiveVisitSetup(optIn: true);
        $recent = Client::factory()
            ->forCompany($setup['company'])
            ->optedInForWhatsAppMarketing()
            ->create(['phone' => '(11) 99999-2222']);

        $recentAppointment = Appointment::factory()
            ->forCompany($setup['company'])
            ->completed()
            ->create([
                'client_id' => $recent->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'start_at' => now()->subDays(3),
                'end_at' => now()->subDays(3)->addHour(),
                'created_by' => $setup['admin']->getKey(),
            ]);
        Attendance::factory()->forAppointment($recentAppointment)->create([
            'completed_by' => $setup['admin']->getKey(),
            'completed_at' => now()->subDays(3),
        ]);

        $campaign = app(WhatsAppCampaignService::class)->create($setup['company'], $setup['admin'], [
            'name' => 'Inativos',
            'audience_type' => WhatsAppCampaignAudience::InactiveSinceDays->value,
            'inactive_since_days' => 30,
            'message_template' => 'Oi {nome} da {empresa}',
            'send_interval_seconds' => 20,
        ]);

        $count = app(WhatsAppCampaignService::class)->prepareRecipients($setup['company'], $campaign);

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('whatsapp_campaign_recipients', [
            'whatsapp_campaign_id' => $campaign->getKey(),
            'client_id' => $setup['client']->getKey(),
        ]);
        $this->assertDatabaseMissing('whatsapp_campaign_recipients', [
            'whatsapp_campaign_id' => $campaign->getKey(),
            'client_id' => $recent->getKey(),
        ]);
    }

    public function test_car_wash_profile_enables_marketing_and_car_wash_copy(): void
    {
        $company = $this->createCompany([
            'business_profile' => CompanyProfile::CarWash,
            'enabled_modules' => null,
        ]);

        $modules = app(CompanyModuleService::class);

        $this->assertTrue($company->isCarWash());
        $this->assertTrue($modules->hasModule($company, CompanyModule::Marketing));
        $this->assertTrue($modules->hasModule($company, CompanyModule::WhatsApp));
        $this->assertSame('Lavador', CompanyTerminology::professional($company));
        $this->assertSame('Tipo de lavagem', CompanyTerminology::service($company));

        $automation = app(WhatsAppAutomationService::class)
            ->getOrCreate($company, WhatsAppAutomationType::WinBack);

        $this->assertStringContainsString('{placa}', $automation->message_template);
    }

    public function test_client_plate_is_normalized(): void
    {
        $company = $this->createCompany(['business_profile' => CompanyProfile::CarWash]);
        $client = app(ClientService::class)->create($company, [
            'name' => 'João',
            'phone' => '(11) 98888-0001',
            'vehicle_plate' => 'abc-1d23',
            'vehicle_model' => 'Onix',
        ]);

        $this->assertSame('ABC1D23', $client->vehicle_plate);
        $this->assertSame('ABC1D23', VehiclePlate::normalize('abc-1d23'));
        $this->assertSame('ABC-1234', VehiclePlate::format('ABC1234'));
    }

    public function test_quiet_hours_skip_without_recording_send(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-02 02:00:00', 'UTC'));

        $setup = $this->createBookableSetup();
        $this->enableOperationalWhatsApp($setup['company']);
        $setup['client']->update(['phone' => '(11) 99999-0001']);

        $this->enableAutomation($setup['company'], WhatsAppAutomationType::Reminder, [
            'is_enabled' => true,
            'delay_value' => 24,
            'quiet_hours_start' => '08:00',
            'quiet_hours_end' => '20:00',
        ]);

        Appointment::factory()
            ->forCompany($setup['company'])
            ->confirmed()
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'start_at' => now()->addHours(10),
                'end_at' => now()->addHours(11),
                'created_by' => $setup['admin']->getKey(),
            ]);

        $queued = app(WhatsAppAutomationService::class)->processCompany($setup['company']);

        $this->assertSame(0, $queued);
        $this->assertDatabaseCount('whatsapp_automation_sends', 0);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function enableAutomation(mixed $company, WhatsAppAutomationType $type, array $data): void
    {
        $defaults = app(WhatsAppAutomationService::class)->getOrCreate($company, $type);

        app(WhatsAppAutomationService::class)->update($company, $type, [
            'message_template' => $defaults->message_template,
            'quiet_hours_start' => '08:00',
            'quiet_hours_end' => '20:00',
            ...$data,
        ]);
    }

    protected function enableOperationalWhatsApp(mixed $company): void
    {
        app(CompanySchedulingSettingService::class)->update($company, [
            'whatsapp_notifications_enabled' => true,
            'whatsapp_instance' => 'loja-1',
            'whatsapp_sender_phone' => '11999998888',
        ]);
    }

    /**
     * @return array{company: mixed, admin: mixed, client: Client, professional: mixed, service: mixed}
     */
    protected function createInactiveVisitSetup(bool $optIn): array
    {
        $setup = $this->createBookableSetup();
        $setup['client']->update([
            'phone' => '(11) 99999-1111',
            'whatsapp_marketing_opt_in' => $optIn,
        ]);

        $appointment = Appointment::factory()
            ->forCompany($setup['company'])
            ->completed()
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'start_at' => now()->subDays(40),
                'end_at' => now()->subDays(40)->addHour(),
                'created_by' => $setup['admin']->getKey(),
            ]);

        Attendance::factory()
            ->forAppointment($appointment)
            ->create([
                'completed_by' => $setup['admin']->getKey(),
                'completed_at' => now()->subDays(40),
            ]);

        return $setup;
    }
}
