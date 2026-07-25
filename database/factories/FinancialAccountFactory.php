<?php

namespace Database\Factories;

use App\Enums\FinancialAccountType;
use App\Models\Company;
use App\Models\FinancialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialAccount>
 */
class FinancialAccountFactory extends Factory
{
    protected $model = FinancialAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => FinancialAccountType::Bank,
            'allow_negative_balance' => false,
            'is_default_receipt_account' => false,
            'is_default_expense_account' => false,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => FinancialAccountType::Cash,
        ]);
    }

    public function bank(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => FinancialAccountType::Bank,
        ]);
    }

    public function allowNegativeBalance(): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_negative_balance' => true,
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
