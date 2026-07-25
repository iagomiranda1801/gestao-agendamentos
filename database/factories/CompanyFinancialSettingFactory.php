<?php

namespace Database\Factories;

use App\Enums\CommissionType;
use App\Models\Company;
use App\Models\CompanyFinancialSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyFinancialSetting>
 */
class CompanyFinancialSettingFactory extends Factory
{
    protected $model = CompanyFinancialSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'default_commission_type' => CommissionType::Percentage,
            'default_commission_value' => '15.0000',
            'materials_reserve_percentage' => '10.0000',
            'business_reserve_percentage' => '10.0000',
            'allow_partial_payments' => true,
            'allow_unpaid_completion' => true,
            'default_payment_due_days' => 0,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }
}
