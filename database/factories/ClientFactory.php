<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'phone_normalized' => fake()->numerify('34#########'),
            'email' => fake()->optional()->safeEmail(),
            'document' => fake()->optional()->numerify('###########'),
            'birth_date' => fake()->optional()->date(),
            'notes' => fake()->optional()->sentence(),
            'vehicle_plate' => null,
            'vehicle_model' => null,
            'is_active' => true,
            'whatsapp_marketing_opt_in' => false,
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

    public function optedInForWhatsAppMarketing(): static
    {
        return $this->state(fn (array $attributes) => [
            'whatsapp_marketing_opt_in' => true,
        ]);
    }
}
