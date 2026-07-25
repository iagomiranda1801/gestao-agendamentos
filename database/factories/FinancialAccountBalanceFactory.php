<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountBalance;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialAccountBalance>
 */
class FinancialAccountBalanceFactory extends Factory
{
    protected $model = FinancialAccountBalance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'financial_account_id' => FinancialAccount::factory(),
            'current_balance' => '0.00',
            'last_transaction_at' => null,
        ];
    }

    public function forAccount(Company $company, FinancialAccount $account): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
            'financial_account_id' => $account->getKey(),
        ]);
    }
}
