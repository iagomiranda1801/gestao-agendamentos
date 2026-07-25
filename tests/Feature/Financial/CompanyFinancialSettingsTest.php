<?php

namespace Tests\Feature\Financial;

use App\Enums\CommissionType;
use App\Enums\CompanyRole;
use App\Services\Financial\CompanyFinancialSettingService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class CompanyFinancialSettingsTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_company_receives_default_financial_settings(): void
    {
        $company = $this->createSchedulingCompany();

        $setting = app(CompanyFinancialSettingService::class)->getOrCreate($company);

        $this->assertSame(CommissionType::Percentage, $setting->default_commission_type);
        $this->assertSame('0.0000', (string) $setting->default_commission_value);
        $this->assertSame('0.0000', (string) $setting->materials_reserve_percentage);
        $this->assertSame('0.0000', (string) $setting->business_reserve_percentage);
        $this->assertTrue($setting->allow_partial_payments);
        $this->assertTrue($setting->allow_unpaid_completion);
        $this->assertSame(0, $setting->default_payment_due_days);
    }

    public function test_negative_percentage_is_rejected(): void
    {
        $company = $this->createSchedulingCompany();

        $this->expectException(ValidationException::class);

        app(CompanyFinancialSettingService::class)->update($company, [
            'materials_reserve_percentage' => '-1',
        ]);
    }

    public function test_distribution_sum_above_100_is_rejected(): void
    {
        $company = $this->createSchedulingCompany();

        $this->expectException(ValidationException::class);

        app(CompanyFinancialSettingService::class)->update($company, [
            'default_commission_type' => CommissionType::Percentage->value,
            'default_commission_value' => '50',
            'materials_reserve_percentage' => '30',
            'business_reserve_percentage' => '25',
        ]);
    }

    public function test_company_admin_can_update_financial_settings(): void
    {
        $company = $this->createSchedulingCompany();
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $setting = app(CompanyFinancialSettingService::class)->getOrCreate($company);

        $this->assertTrue($admin->can('update', $setting));
    }

    public function test_manager_cannot_update_financial_settings(): void
    {
        $company = $this->createSchedulingCompany();
        $manager = $this->createCompanyUser($company, [], CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        $setting = app(CompanyFinancialSettingService::class)->getOrCreate($company);

        $this->assertFalse($manager->can('update', $setting));
    }

    public function test_manager_can_view_financial_settings_page(): void
    {
        $company = $this->createSchedulingCompany();
        $manager = $this->createCompanyUser($company, [], CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        $this->get(route('filament.app.pages.configuracoes-financeiras', ['tenant' => $company]))
            ->assertOk();
    }

    public function test_employee_cannot_access_financial_settings_page(): void
    {
        $company = $this->createSchedulingCompany();
        $employee = $this->createCompanyUser($company, [], CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        $this->get(route('filament.app.pages.configuracoes-financeiras', ['tenant' => $company]))
            ->assertForbidden();
    }

    public function test_user_from_other_company_cannot_update_settings(): void
    {
        $company = $this->createSchedulingCompany();
        $other = $this->createSchedulingCompany();
        $user = $this->createCompanyUser($other, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($user, $company);

        $this->assertFalse($user->can('update', app(CompanyFinancialSettingService::class)->getOrCreate($company)));
    }

    public function test_owner_allocation_percentage_is_calculated(): void
    {
        $company = $this->createSchedulingCompany();

        $setting = app(CompanyFinancialSettingService::class)->update($company, [
            'default_commission_type' => CommissionType::Percentage->value,
            'default_commission_value' => '15',
            'materials_reserve_percentage' => '10',
            'business_reserve_percentage' => '10',
        ]);

        $this->assertSame('65.0000', $setting->ownerAllocationPercentage());
    }
}
