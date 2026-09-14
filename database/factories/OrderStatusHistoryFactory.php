<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusHistory>
 */
class OrderStatusHistoryFactory extends Factory
{
    protected $model = OrderStatusHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'order_id' => Order::factory(),
            'user_id' => null,
            'from_status' => null,
            'to_status' => OrderStatus::Received,
        ];
    }
}
