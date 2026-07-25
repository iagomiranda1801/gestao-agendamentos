<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\Company;
use App\Models\Professional;
use App\Models\ProfessionalBreak;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfessionalBreak>
 */
class ProfessionalBreakFactory extends Factory
{
    protected $model = ProfessionalBreak::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'professional_id' => Professional::factory(),
            'name' => 'Almoço',
            'weekday' => Weekday::Monday->value,
            'start_time' => '12:00:00',
            'end_time' => '13:00:00',
            'valid_from' => null,
            'valid_until' => null,
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
