<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            TenantFoundationSeeder::class,
            MeasurementUnitSeeder::class,
            DemoDataSeeder::class,
            EstudioAnaCatalogSeeder::class,
            EstudioAnaOpeningInventorySeeder::class,
            EstudioAnaScheduleSeeder::class,
            EstudioAnaPublicBookingSeeder::class,
            EstudioAnaFinancialSettingsSeeder::class,
            EstudioAnaFinanceStructureSeeder::class,
        ]);
    }
}
