<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Professional>
 */
class ProfessionalFactory extends Factory
{
    protected $model = Professional::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => null,
            'name' => fake()->name(),
            'phone' => fake()->optional()->phoneNumber(),
            'phone_normalized' => fake()->optional()->numerify('34#########'),
            'email' => fake()->optional()->safeEmail(),
            'document' => fake()->optional()->numerify('###########'),
            'specialty' => fake()->optional()->jobTitle(),
            'color' => fake()->optional()->hexColor(),
            'notes' => fake()->optional()->sentence(),
            'is_bookable' => true,
            'sort_order' => fake()->numberBetween(0, 10),
            'is_active' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function linkedToUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->getKey(),
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
        ]);
    }
}
