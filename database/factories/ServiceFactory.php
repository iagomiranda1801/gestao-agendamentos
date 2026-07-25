<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    protected $model = Service::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'company_id' => Company::factory(),
            'name' => ucfirst($name),
            'slug' => Str::slug($name),
            'description' => fake()->optional()->sentence(),
            'price' => fake()->randomFloat(2, 25, 200),
            'duration_minutes' => fake()->numberBetween(15, 120),
            'color' => fake()->optional()->hexColor(),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
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

    public function bookable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_bookable' => true,
        ]);
    }

    public function notBookable(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_bookable' => false,
            'is_online_booking_enabled' => false,
        ]);
    }

    public function onlineBookingEnabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_bookable' => true,
            'is_online_booking_enabled' => true,
        ]);
    }

    public function onlineBookingDisabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_online_booking_enabled' => false,
        ]);
    }
}
