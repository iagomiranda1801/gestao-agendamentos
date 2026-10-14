<?php

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use Database\Seeders\ModulePriceSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (CompanyModule::cases() as $module) {
            foreach (BillingInterval::cases() as $interval) {
                DB::table('module_prices')->updateOrInsert(
                    [
                        'module' => $module->value,
                        'interval' => $interval->value,
                    ],
                    [
                        'price_cents' => ModulePriceSeeder::MONTHLY_CENTS * $interval->catalogMonthlyMultiplier(),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
        }
    }

    public function down(): void
    {
        // Catálogo anterior era heterogêneo; não restauramos valores antigos.
    }
};
