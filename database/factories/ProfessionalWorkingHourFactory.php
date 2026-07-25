<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\Company;
use App\Models\Professional;
use App\Models\ProfessionalWorkingHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfessionalWorkingHour>
 */
class ProfessionalWorkingHourFactory extends Factory
{
    protected $model = ProfessionalWorkingHour::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'professional_id' => Professional::factory(),
            'weekday' => Weekday::Monday->value,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'valid_from' => null,
            'valid_until' => null,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }
}
