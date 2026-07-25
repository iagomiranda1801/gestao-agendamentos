<?php

namespace Database\Factories;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDay()->setTime(10, 0);
        $duration = 60;

        return [
            'company_id' => Company::factory(),
            'client_id' => Client::factory(),
            'professional_id' => Professional::factory(),
            'service_id' => Service::factory(),
            'status' => AppointmentStatus::Confirmed,
            'origin' => AppointmentOrigin::Internal,
            'reference_key' => null,
            'start_at' => $start,
            'end_at' => $start->copy()->addMinutes($duration),
            'service_name_snapshot' => fake()->words(3, true),
            'price_snapshot' => fake()->randomFloat(2, 30, 200),
            'duration_minutes_snapshot' => $duration,
            'buffer_before_minutes_snapshot' => 0,
            'buffer_after_minutes_snapshot' => 0,
            'notes' => fake()->optional()->sentence(),
            'internal_notes' => fake()->optional()->sentence(),
            'cancellation_reason' => null,
            'created_by' => User::factory(),
            'updated_by' => null,
            'cancelled_by' => null,
            'confirmed_by' => null,
            'started_by' => null,
            'confirmed_at' => null,
            'started_at' => null,
            'cancelled_at' => null,
            'no_show_at' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::Pending,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::Confirmed,
        ]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::InProgress,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::Completed,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::Cancelled,
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => AppointmentStatus::NoShow,
        ]);
    }

    public function internal(): static
    {
        return $this->state(fn (array $attributes) => [
            'origin' => AppointmentOrigin::Internal,
        ]);
    }

    public function online(): static
    {
        return $this->state(fn (array $attributes) => [
            'origin' => AppointmentOrigin::Online,
            'created_by' => null,
            'public_booked_at' => now(),
            'public_confirmation_code' => strtoupper(fake()->bothify('???-######')),
            'client_name_snapshot' => fake()->name(),
            'client_phone_snapshot' => fake()->phoneNumber(),
        ]);
    }
}
