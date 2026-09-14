<?php

namespace Database\Factories;

use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(2000, 12000);

        return [
            'company_id' => Company::factory(),
            'number' => fake()->unique()->numberBetween(1, 99999),
            'public_code' => strtoupper(Str::random(8)),
            'status' => OrderStatus::Received,
            'fulfillment' => OrderFulfillment::Pickup,
            'customer_name' => fake()->name(),
            'customer_phone' => '(34) 99999-0001',
            'customer_phone_normalized' => '34999990001',
            'customer_email' => fake()->optional()->safeEmail(),
            'subtotal_cents' => $subtotal,
            'delivery_fee_cents' => 0,
            'total_cents' => $subtotal,
            'notes' => fake()->optional()->sentence(),
            'received_at' => now(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function delivery(): static
    {
        return $this->state(fn (array $attributes) => [
            'fulfillment' => OrderFulfillment::Delivery,
            'delivery_address' => fake()->streetAddress(),
            'delivery_neighborhood' => 'Centro',
            'delivery_city' => 'Uberlândia',
            'delivery_fee_cents' => 800,
            'total_cents' => ((int) ($attributes['subtotal_cents'] ?? 2000)) + 800,
        ]);
    }

    public function dineIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'fulfillment' => OrderFulfillment::DineIn,
            'delivery_fee_cents' => 0,
            'total_cents' => (int) ($attributes['subtotal_cents'] ?? 2000),
            'table_id' => null,
        ]);
    }
}
