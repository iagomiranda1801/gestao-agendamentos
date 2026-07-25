<?php

namespace Database\Factories;

use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentHistory;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AppointmentHistory>
 */
class AppointmentHistoryFactory extends Factory
{
    protected $model = AppointmentHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'appointment_id' => Appointment::factory(),
            'user_id' => User::factory(),
            'type' => AppointmentHistoryType::Created,
            'old_status' => null,
            'new_status' => AppointmentStatus::Confirmed,
            'old_start_at' => null,
            'new_start_at' => now()->addDay(),
            'old_end_at' => null,
            'new_end_at' => now()->addDay()->addHour(),
            'description' => null,
            'metadata' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }
}
