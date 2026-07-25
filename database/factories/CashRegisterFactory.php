<?php

namespace Database\Factories;

use App\Models\CashRegister;
use App\Models\Company;
use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashRegister>
 */
class CashRegisterFactory extends Factory
{
    protected $model = CashRegister::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'financial_account_id' => FinancialAccount::factory()->cash(),
            'name' => fake()->unique()->words(2, true),
            'is_active' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
            'financial_account_id' => FinancialAccount::factory()->forCompany($company)->cash(),
        ]);
    }
}
