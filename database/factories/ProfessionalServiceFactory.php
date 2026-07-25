<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Professional;
use App\Models\ProfessionalServiceLink;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProfessionalServiceLink>
 */
class ProfessionalServiceFactory extends Factory
{
    protected $model = ProfessionalServiceLink::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'professional_id' => Professional::factory(),
            'service_id' => Service::factory(),
            'custom_price' => null,
            'custom_duration_minutes' => null,
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
