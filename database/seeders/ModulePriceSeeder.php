<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Models\ModulePrice;
use Illuminate\Database\Seeder;

class ModulePriceSeeder extends Seeder
{
    /** Preço mensal mínimo por módulo (R$ 49,90). */
    public const MONTHLY_CENTS = 4990;

    public function run(): void
    {
        foreach (CompanyModule::cases() as $module) {
            foreach (BillingInterval::cases() as $interval) {
                ModulePrice::query()->updateOrCreate(
                    [
                        'module' => $module->value,
                        'interval' => $interval->value,
                    ],
                    [
                        'price_cents' => self::MONTHLY_CENTS * $interval->catalogMonthlyMultiplier(),
                    ],
                );
            }
        }
    }
}
