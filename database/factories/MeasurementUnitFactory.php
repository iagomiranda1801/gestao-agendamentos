<?php

namespace Database\Factories;

use App\Enums\MeasurementUnitCategory;
use App\Models\MeasurementUnit;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MeasurementUnit>
 */
class MeasurementUnitFactory extends Factory
{
    protected $model = MeasurementUnit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => ucfirst($name),
            'symbol' => Str::substr($name, 0, 3),
            'code' => Str::slug($name),
            'category' => fake()->randomElement(MeasurementUnitCategory::cases()),
            'decimal_places' => 4,
            'is_active' => true,
        ];
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
}
