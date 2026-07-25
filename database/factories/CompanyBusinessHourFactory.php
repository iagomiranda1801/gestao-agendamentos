<?php

namespace Database\Factories;

use App\Enums\Weekday;
use App\Models\Company;
use App\Models\CompanyBusinessHour;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompanyBusinessHour>
 */
class CompanyBusinessHourFactory extends Factory
{
    protected $model = CompanyBusinessHour::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'weekday' => Weekday::Monday->value,
            'start_time' => '08:00:00',
            'end_time' => '18:00:00',
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
