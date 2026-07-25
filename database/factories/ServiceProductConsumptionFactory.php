<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceProductConsumption;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceProductConsumption>
 */
class ServiceProductConsumptionFactory extends Factory
{
    protected $model = ServiceProductConsumption::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'service_id' => Service::factory(),
            'product_id' => Product::factory(),
            'quantity' => fake()->randomFloat(4, 0.1, 10),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function forService(Service $service): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $service->company_id,
            'service_id' => $service->getKey(),
        ]);
    }

    public function forProduct(Product $product): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $product->company_id,
            'product_id' => $product->getKey(),
        ]);
    }
}
