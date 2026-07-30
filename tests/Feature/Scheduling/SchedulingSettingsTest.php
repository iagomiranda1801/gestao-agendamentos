<?php

namespace Tests\Feature\Scheduling;

use App\Enums\CompanyRole;
use App\Enums\Weekday;
use App\Services\Scheduling\CompanySchedulingSettingService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class SchedulingSettingsTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_company_has_default_scheduling_settings(): void
    {
        $company = $this->createSchedulingCompany();

        $setting = app(CompanySchedulingSettingService::class)->getOrCreate($company);

        $this->assertSame(15, $setting->slot_interval_minutes);
        $this->assertSame('07:00:00', substr((string) $setting->calendar_start_time, 0, 8));
        $this->assertSame('22:00:00', substr((string) $setting->calendar_end_time, 0, 8));
        $this->assertSame(Weekday::Monday->value, $setting->week_starts_on);
    }

    public function test_invalid_slot_interval_is_rejected(): void
    {
        $company = $this->createSchedulingCompany();

        $this->expectException(ValidationException::class);

        app(CompanySchedulingSettingService::class)->update($company, [
            'slot_interval_minutes' => 7,
        ]);
    }

    public function test_calendar_end_before_start_is_rejected(): void
    {
        $company = $this->createSchedulingCompany();

        $this->expectException(ValidationException::class);

        app(CompanySchedulingSettingService::class)->update($company, [
            'calendar_start_time' => '18:00:00',
            'calendar_end_time' => '08:00:00',
        ]);
    }

    public function test_user_from_other_company_cannot_update_settings(): void
    {
        $company = $this->createSchedulingCompany();
        $other = $this->createSchedulingCompany();
        $user = $this->createCompanyUser($other, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($user, $company);

        $this->assertFalse($user->can('update', app(CompanySchedulingSettingService::class)->getOrCreate($company)));
    }

    public function test_manager_cannot_update_general_settings(): void
    {
        $company = $this->createSchedulingCompany();
        $manager = $this->createCompanyUser($company, [], CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        $this->assertFalse($manager->can('update', app(CompanySchedulingSettingService::class)->getOrCreate($company)));
    }

    public function test_employee_cannot_access_settings_page(): void
    {
        $company = $this->createSchedulingCompany();
        $employee = $this->createCompanyUser($company, [], CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        $this->get(route('filament.app.pages.configuracoes-agenda', ['tenant' => $company]))
            ->assertForbidden();
    }

    public function test_admin_can_render_settings_page(): void
    {
        $company = $this->createSchedulingCompany();
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(route('filament.app.pages.configuracoes-agenda', ['tenant' => $company]))
            ->assertOk()
            ->assertSee('Configurações da agenda');
    }
}
