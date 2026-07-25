<?php

namespace Database\Factories;

use App\Enums\AttendanceHistoryType;
use App\Models\Attendance;
use App\Models\AttendanceHistory;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceHistory>
 */
class AttendanceHistoryFactory extends Factory
{
    protected $model = AttendanceHistory::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'attendance_id' => Attendance::factory(),
            'user_id' => User::factory(),
            'type' => AttendanceHistoryType::Completed,
            'description' => fake()->optional()->sentence(),
            'metadata' => null,
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
