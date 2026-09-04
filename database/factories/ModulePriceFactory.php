<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Models\ModulePrice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModulePrice>
 */
class ModulePriceFactory extends Factory
{
    protected $model = ModulePrice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'module' => CompanyModule::Scheduling,
            'interval' => BillingInterval::Monthly,
            'price_cents' => 4900,
        ];
    }
}
