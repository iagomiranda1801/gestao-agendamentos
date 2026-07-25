<?php

namespace Database\Factories;

use App\Enums\RecurrenceFrequency;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\RecurringExpenseTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringExpenseTemplate>
 */
class RecurringExpenseTemplateFactory extends Factory
{
    protected $model = RecurringExpenseTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'description' => fake()->sentence(3),
            'frequency' => RecurrenceFrequency::Monthly,
            'amount' => number_format(fake()->randomFloat(2, 100, 1000), 2, '.', ''),
            'starts_on' => now()->startOfMonth()->toDateString(),
            'day_of_month' => 10,
            'next_generation_date' => now()->startOfMonth()->toDateString(),
            'generate_days_in_advance' => 10,
            'auto_generate' => true,
            'is_active' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
            'expense_category_id' => ExpenseCategory::factory()->forCompany($company),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }
}
