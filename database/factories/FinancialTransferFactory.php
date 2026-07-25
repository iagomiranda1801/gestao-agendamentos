<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialTransfer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialTransfer>
 */
class FinancialTransferFactory extends Factory
{
    protected $model = FinancialTransfer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'from_financial_account_id' => FinancialAccount::factory(),
            'to_financial_account_id' => FinancialAccount::factory(),
            'amount' => '100.00',
            'fee_amount' => '0.00',
            'occurred_at' => now(),
            'description' => fake()->sentence(),
            'created_by' => User::factory(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }
}
