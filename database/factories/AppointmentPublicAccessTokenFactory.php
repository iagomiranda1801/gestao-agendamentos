<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\AppointmentPublicAccessToken;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentPublicAccessToken>
 */
class AppointmentPublicAccessTokenFactory extends Factory
{
    protected $model = AppointmentPublicAccessToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'appointment_id' => Appointment::factory()->online(),
            'token_hash' => hash('sha256', fake()->uuid()),
            'expires_at' => now()->addDays(30),
            'last_used_at' => null,
            'revoked_at' => null,
        ];
    }

    public function forAppointment(Appointment $appointment): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $appointment->company_id,
            'appointment_id' => $appointment->getKey(),
        ]);
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addDays(30),
            'revoked_at' => null,
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
            'revoked_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->addDays(30),
            'revoked_at' => now(),
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }
}
