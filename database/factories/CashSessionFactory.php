<?php

namespace Database\Factories;

use App\Enums\CashSessionStatus;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashSession>
 */
class CashSessionFactory extends Factory
{
    protected $model = CashSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'cash_register_id' => CashRegister::factory(),
            'status' => CashSessionStatus::Open,
            'opened_by' => User::factory(),
            'opened_at' => now(),
            'expected_opening_amount' => '0.00',
            'counted_opening_amount' => '0.00',
            'opening_difference_amount' => '0.00',
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
            'cash_register_id' => CashRegister::factory()->forCompany($company),
        ]);
    }

    public function open(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CashSessionStatus::Open,
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => CashSessionStatus::Closed,
            'closed_by' => User::factory(),
            'closed_at' => now(),
            'expected_closing_amount' => '0.00',
            'counted_closing_amount' => '0.00',
            'closing_difference_amount' => '0.00',
        ]);
    }
}
