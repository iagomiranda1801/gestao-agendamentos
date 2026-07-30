<?php

namespace Database\Factories;

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\MeasurementUnit;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'measurement_unit_id' => MeasurementUnit::factory(),
            'name' => fake()->unique()->words(2, true),
            'sku' => fake()->optional()->bothify('SKU-####'),
            'barcode' => null,
            'type' => ProductType::Consumable,
            'description' => fake()->optional()->sentence(),
            'reference_unit_cost' => fake()->randomFloat(6, 0.01, 100),
            'sale_price' => fake()->randomFloat(2, 1, 200),
            'minimum_stock' => fake()->randomFloat(4, 0, 50),
            'tracks_stock' => true,
            'is_sellable' => false,
            'notes' => fake()->optional()->sentence(),
            'is_active' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function consumable(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductType::Consumable,
        ]);
    }

    public function asset(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductType::Asset,
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

    public function notSellable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_sellable' => false,
        ]);
    }

    public function sellable(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => ProductType::Sale,
            'is_sellable' => true,
            'sale_price' => fake()->randomFloat(2, 1, 200),
        ]);
    }
}
