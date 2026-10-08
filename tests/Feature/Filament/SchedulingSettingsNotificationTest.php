<?php

namespace Tests\Feature\Filament;

use App\Enums\CompanyProfile;
use App\Filament\App\Pages\SchedulingSettingsPage;
use App\Services\Scheduling\CompanySchedulingSettingService;
use Livewire\Livewire;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class SchedulingSettingsNotificationTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_scheduling_settings_save_shows_success_notification(): void
    {
        $company = $this->createSchedulingCompany(['slug' => 'settings-notif']);
        $admin = $this->createCompanyUser($company);
        $this->seedStandardBusinessHours($company);
        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(SchedulingSettingsPage::class)
            ->fillForm([
                'slot_interval_minutes' => 15,
                'calendar_start_time' => '07:00',
                'calendar_end_time' => '22:00',
                'week_starts_on' => 1,
                'default_calendar_view' => 'timeGridWeek',
                'allow_employee_self_view' => true,
                'business_hours' => [
                    [
                        'weekday' => 1,
                        'start_time' => '08:00',
                        'end_time' => '18:00',
                        'is_active' => true,
                    ],
                ],
            ])
            ->call('save')
            ->assertNotified('Configurações salvas');
    }

    public function test_company_can_save_ai_key_without_exposing_or_erasing_it_on_next_save(): void
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $admin = $this->createCompanyUser($company);
        $this->seedStandardBusinessHours($company);
        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(SchedulingSettingsPage::class)
            ->fillForm(['ai_provider' => 'openai', 'ai_model' => 'gpt-4.1-mini',
                'ai_api_key' => 'tenant-secret-key', 'tattoo_ai_enabled' => true])
            ->call('save')->assertNotified('Configurações salvas');

        $setting = app(CompanySchedulingSettingService::class)->getOrCreate($company);
        $this->assertSame('tenant-secret-key', $setting->ai_api_key);
        $this->assertNotSame('tenant-secret-key', $setting->getRawOriginal('ai_api_key'));

        Livewire::test(SchedulingSettingsPage::class)
            ->assertFormSet(['ai_api_key' => ''])
            ->call('save')->assertNotified('Configurações salvas');
        $this->assertSame('tenant-secret-key', $setting->fresh()->ai_api_key);
    }
}
