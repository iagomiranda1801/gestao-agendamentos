<?php

namespace Database\Factories;

use App\Enums\FinancialTransactionDirection;
use App\Enums\FinancialTransactionType;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialTransaction>
 */
class FinancialTransactionFactory extends Factory
{
    protected $model = FinancialTransaction::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'financial_account_id' => FinancialAccount::factory(),
            'direction' => FinancialTransactionDirection::Inbound,
            'type' => FinancialTransactionType::CustomerPayment,
            'amount' => '100.00',
            'occurred_at' => now(),
            'description' => fake()->sentence(3),
            'created_by' => User::factory(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
            'financial_account_id' => FinancialAccount::factory()->forCompany($company),
        ]);
    }

    public function inbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => FinancialTransactionDirection::Inbound,
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => FinancialTransactionDirection::Outbound,
        ]);
    }
}
