<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Pages\SchedulingSettingsPage;
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
}
