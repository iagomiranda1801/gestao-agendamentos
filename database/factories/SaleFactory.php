<?php

namespace Database\Factories;

use App\Enums\SaleOrigin;
use App\Enums\SaleStatus;
use App\Models\Client;
use App\Models\Company;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $finalAmount = number_format(fake()->randomFloat(2, 20, 500), 2, '.', '');

        return [
            'company_id' => Company::factory(),
            'client_id' => Client::factory(),
            'status' => SaleStatus::Completed,
            'origin' => SaleOrigin::Pos,
            'gross_amount' => $finalAmount,
            'discount_amount' => '0.00',
            'final_amount' => $finalAmount,
            'paid_amount' => '0.00',
            'outstanding_amount' => $finalAmount,
            'sold_by' => User::factory(),
            'sold_at' => now(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
            'client_id' => Client::factory()->forCompany($company),
        ]);
    }
}
