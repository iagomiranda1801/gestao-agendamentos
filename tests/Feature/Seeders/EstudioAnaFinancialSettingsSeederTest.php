<?php

namespace Tests\Feature\Seeders;

use App\Enums\CommissionType;
use App\Models\Company;
use App\Models\CompanyFinancialSetting;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EstudioAnaFinancialSettingsSeeder;
use Database\Seeders\MeasurementUnitSeeder;
use Database\Seeders\TenantFoundationSeeder;
use Tests\TestCase;

class EstudioAnaFinancialSettingsSeederTest extends TestCase
{
    protected function seedPrerequisites(): Company
    {
        $this->seed(TenantFoundationSeeder::class);
        $this->seed(MeasurementUnitSeeder::class);
        $this->seed(DemoDataSeeder::class);

        return Company::query()->where('slug', 'estudio-ana')->firstOrFail();
    }

    public function test_seeder_creates_financial_settings_for_estudio_ana(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinancialSettingsSeeder::class);

        $this->assertDatabaseHas('company_financial_settings', [
            'company_id' => $company->getKey(),
        ]);
    }

    public function test_seeder_sets_commission_type_to_percentage(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinancialSettingsSeeder::class);

        $setting = CompanyFinancialSetting::query()
            ->where('company_id', $company->getKey())
            ->firstOrFail();

        $this->assertSame(CommissionType::Percentage, $setting->default_commission_type);
    }

    public function test_seeder_sets_commission_value_to_15(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinancialSettingsSeeder::class);

        $setting = CompanyFinancialSetting::query()
            ->where('company_id', $company->getKey())
            ->firstOrFail();

        $this->assertSame('15.0000', (string) $setting->default_commission_value);
    }

    public function test_seeder_sets_materials_reserve_to_10(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinancialSettingsSeeder::class);

        $setting = CompanyFinancialSetting::query()
            ->where('company_id', $company->getKey())
            ->firstOrFail();

        $this->assertSame('10.0000', (string) $setting->materials_reserve_percentage);
    }

    public function test_seeder_sets_business_reserve_to_10(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinancialSettingsSeeder::class);

        $setting = CompanyFinancialSetting::query()
            ->where('company_id', $company->getKey())
            ->firstOrFail();

        $this->assertSame('10.0000', (string) $setting->business_reserve_percentage);
    }

    public function test_seeder_allows_partial_and_unpaid_completion(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinancialSettingsSeeder::class);

        $setting = CompanyFinancialSetting::query()
            ->where('company_id', $company->getKey())
            ->firstOrFail();

        $this->assertTrue($setting->allow_partial_payments);
        $this->assertTrue($setting->allow_unpaid_completion);
    }

    public function test_seeder_sets_due_days_to_zero(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinancialSettingsSeeder::class);

        $setting = CompanyFinancialSetting::query()
            ->where('company_id', $company->getKey())
            ->firstOrFail();

        $this->assertSame(0, $setting->default_payment_due_days);
    }

    public function test_seeder_is_idempotent(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinancialSettingsSeeder::class);
        $settings = CompanyFinancialSetting::query()
            ->where('company_id', $company->getKey())
            ->firstOrFail()
            ->toArray();

        $this->seed(EstudioAnaFinancialSettingsSeeder::class);
        $settingsAfterSecondRun = CompanyFinancialSetting::query()
            ->where('company_id', $company->getKey())
            ->firstOrFail()
            ->toArray();

        $this->assertSame(1, CompanyFinancialSetting::query()->where('company_id', $company->getKey())->count());
        $this->assertSame($settings['default_commission_value'], $settingsAfterSecondRun['default_commission_value']);
        $this->assertSame($settings['materials_reserve_percentage'], $settingsAfterSecondRun['materials_reserve_percentage']);
        $this->assertSame($settings['business_reserve_percentage'], $settingsAfterSecondRun['business_reserve_percentage']);
        $this->assertSame($settings['default_payment_due_days'], $settingsAfterSecondRun['default_payment_due_days']);
    }
}
