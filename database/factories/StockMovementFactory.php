<?php

namespace Database\Factories;

use App\Enums\StockMovementDirection;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'product_id' => Product::factory(),
            'stock_document_id' => StockDocument::factory()->posted(),
            'direction' => StockMovementDirection::Inbound,
            'quantity' => fake()->randomFloat(4, 1, 10),
            'unit_cost' => fake()->randomFloat(6, 0.01, 100),
            'total_cost' => fake()->randomFloat(6, 1, 1000),
            'quantity_before' => 0,
            'quantity_after' => fake()->randomFloat(4, 1, 10),
            'average_cost_before' => 0,
            'average_cost_after' => fake()->randomFloat(6, 0.01, 100),
            'occurred_at' => now(),
            'created_by' => User::factory(),
        ];
    }

    public function inbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => StockMovementDirection::Inbound,
        ]);
    }

    public function outbound(): static
    {
        return $this->state(fn (array $attributes) => [
            'direction' => StockMovementDirection::Outbound,
        ]);
    }
}
