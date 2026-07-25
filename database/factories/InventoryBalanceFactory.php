<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryBalance>
 */
class InventoryBalanceFactory extends Factory
{
    protected $model = InventoryBalance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'product_id' => Product::factory(),
            'quantity_on_hand' => fake()->randomFloat(4, 0, 100),
            'average_unit_cost' => fake()->randomFloat(6, 0.01, 50),
            'last_movement_at' => now(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $product->company_id,
            'product_id' => $product->getKey(),
        ]);
    }
}
