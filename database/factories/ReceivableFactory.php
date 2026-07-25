<?php

namespace Database\Factories;

use App\Enums\ReceivableStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Receivable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receivable>
 */
class ReceivableFactory extends Factory
{
    protected $model = Receivable::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $originalAmount = number_format(fake()->randomFloat(2, 50, 500), 2, '.', '');

        return [
            'company_id' => Company::factory(),
            'attendance_id' => Attendance::factory(),
            'client_id' => fn (array $attributes) => Attendance::query()
                ->find($attributes['attendance_id'])?->client_id
                ?? Attendance::factory()->create()->client_id,
            'original_amount' => $originalAmount,
            'paid_amount' => '0.00',
            'outstanding_amount' => $originalAmount,
            'status' => ReceivableStatus::Open,
            'due_date' => now()->addDays(7)->toDateString(),
            'settled_at' => null,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
            'attendance_id' => Attendance::factory()->forCompany($company),
        ]);
    }
}
