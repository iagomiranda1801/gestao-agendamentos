<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['Pequeno', 'Média', 'Grande']),
            'price' => fake()->randomFloat(2, 8, 60),
            'sort_order' => 10,
            'is_default' => false,
            'is_active' => true,
        ];
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $product->company_id,
            'product_id' => $product->getKey(),
        ]);
    }
}
