<?php

namespace Database\Factories;

use App\Models\Attendance;
use App\Models\AttendanceMaterial;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceMaterial>
 */
class AttendanceMaterialFactory extends Factory
{
    protected $model = AttendanceMaterial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(4, 0.1, 5);
        $unitCost = fake()->randomFloat(6, 1, 50);

        return [
            'company_id' => Company::factory(),
            'attendance_id' => Attendance::factory(),
            'product_id' => Product::factory(),
            'service_product_consumption_id' => null,
            'product_name_snapshot' => fake()->words(2, true),
            'planned_quantity' => $quantity,
            'quantity' => $quantity,
            'unit_cost_snapshot' => $unitCost,
            'total_cost' => bcmul((string) $quantity, (string) $unitCost, 6),
            'tracks_stock_snapshot' => true,
            'stock_document_id' => null,
            'stock_document_item_id' => null,
            'stock_movement_id' => null,
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forAttendance(Attendance $attendance): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $attendance->company_id,
            'attendance_id' => $attendance->getKey(),
        ]);
    }
}
