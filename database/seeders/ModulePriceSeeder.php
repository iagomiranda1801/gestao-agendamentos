<?php

namespace Database\Seeders;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Models\ModulePrice;
use Illuminate\Database\Seeder;

class ModulePriceSeeder extends Seeder
{
    /**
     * Faixa entrada: mensal; semestral = 5×; anual = 10×.
     *
     * @var array<string, int>
     */
    private const MONTHLY_CENTS = [
        CompanyModule::Scheduling->value => 4900,
        CompanyModule::Sales->value => 3900,
        CompanyModule::Finance->value => 3900,
        CompanyModule::Stock->value => 2500,
        CompanyModule::ClinicalRecords->value => 4900,
        CompanyModule::WhatsApp->value => 1900,
        CompanyModule::Marketing->value => 3900,
    ];

    public function run(): void
    {
        foreach (self::MONTHLY_CENTS as $module => $monthlyCents) {
            foreach (BillingInterval::cases() as $interval) {
                $multiplier = match ($interval) {
                    BillingInterval::Monthly => 1,
                    BillingInterval::Semiannual => 5,
                    BillingInterval::Annual => 10,
                };

                ModulePrice::query()->updateOrCreate(
                    [
                        'module' => $module,
                        'interval' => $interval->value,
                    ],
                    [
                        'price_cents' => $monthlyCents * $multiplier,
                    ],
                );
            }
        }
    }
}
