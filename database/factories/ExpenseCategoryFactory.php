<?php

namespace Database\Factories;

use App\Enums\ExpenseCategoryType;
use App\Models\Company;
use App\Models\ExpenseCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExpenseCategory>
 */
class ExpenseCategoryFactory extends Factory
{
    protected $model = ExpenseCategory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->unique()->words(2, true),
            'code' => fake()->optional()->slug(2),
            'type' => ExpenseCategoryType::Operational,
            'description' => fake()->optional()->sentence(),
            'affects_managerial_result' => true,
            'is_system' => false,
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

    public function stockPurchase(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Compra de estoque',
            'type' => ExpenseCategoryType::StockPurchase,
            'affects_managerial_result' => false,
            'is_system' => true,
        ]);
    }

    public function professionalCommissions(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Comissões profissionais',
            'code' => 'professional-commissions',
            'type' => ExpenseCategoryType::Personnel,
            'affects_managerial_result' => false,
            'is_system' => true,
        ]);
    }

    public function system(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_system' => true,
        ]);
    }
}
