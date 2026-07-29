<?php

namespace Database\Factories;

use App\Enums\PayableOrigin;
use App\Enums\PayableStatus;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\Payable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payable>
 */
class PayableFactory extends Factory
{
    protected $model = Payable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = number_format(fake()->randomFloat(2, 50, 500), 2, '.', '');

        return [
            'company_id' => Company::factory(),
            'expense_category_id' => ExpenseCategory::factory(),
            'origin' => PayableOrigin::Manual,
            'status' => PayableStatus::Draft,
            'description' => fake()->sentence(3),
            'issue_date' => now()->toDateString(),
            'competence_date' => now()->toDateString(),
            'total_amount' => $amount,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
            'expense_category_id' => ExpenseCategory::factory()->forCompany($company),
        ]);
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayableStatus::Open,
        ]);
    }

    public function partial(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayableStatus::Partial,
        ]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayableStatus::Paid,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayableStatus::Cancelled,
        ]);
    }

    public function overdue(): static
    {
        return $this->open();
    }

    public function stockPurchase(): static
    {
        return $this->state(fn (array $attributes) => [
            'origin' => PayableOrigin::StockPurchase,
            'expense_category_id' => ExpenseCategory::factory()->stockPurchase(),
        ]);
    }

    public function recurring(): static
    {
        return $this->state(fn (array $attributes) => [
            'origin' => PayableOrigin::Recurring,
        ]);
    }

    public function professionalCommission(): static
    {
        return $this->state(fn (array $attributes) => [
            'origin' => PayableOrigin::ProfessionalCommission,
        ]);
    }
}
