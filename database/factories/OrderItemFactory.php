<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderItem>
 */
class OrderItemFactory extends Factory
{
    protected $model = OrderItem::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->numberBetween(1, 3);
        $unitPrice = fake()->numberBetween(800, 4500);

        return [
            'order_id' => Order::factory(),
            'product_id' => null,
            'name' => fake()->words(2, true),
            'unit_price_cents' => $unitPrice,
            'quantity' => $quantity,
            'line_total_cents' => $unitPrice * $quantity,
            'notes' => null,
        ];
    }
}
