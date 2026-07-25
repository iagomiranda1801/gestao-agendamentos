<?php

namespace Database\Seeders;

use App\Enums\MeasurementUnitCategory;
use App\Models\MeasurementUnit;
use Illuminate\Database\Seeder;

class MeasurementUnitSeeder extends Seeder
{
    public function run(): void
    {
        $units = [
            [
                'code' => 'unit',
                'name' => 'Unidade',
                'symbol' => 'un',
                'category' => MeasurementUnitCategory::Count,
                'decimal_places' => 0,
            ],
            [
                'code' => 'use',
                'name' => 'Uso',
                'symbol' => 'uso',
                'category' => MeasurementUnitCategory::Custom,
                'decimal_places' => 4,
            ],
            [
                'code' => 'milliliter',
                'name' => 'Mililitro',
                'symbol' => 'ml',
                'category' => MeasurementUnitCategory::Volume,
                'decimal_places' => 4,
            ],
            [
                'code' => 'gram',
                'name' => 'Grama',
                'symbol' => 'g',
                'category' => MeasurementUnitCategory::Mass,
                'decimal_places' => 4,
            ],
            [
                'code' => 'sheet',
                'name' => 'Folha',
                'symbol' => 'folha',
                'category' => MeasurementUnitCategory::Count,
                'decimal_places' => 4,
            ],
        ];

        foreach ($units as $unit) {
            MeasurementUnit::query()->updateOrCreate(
                ['code' => $unit['code']],
                [
                    'name' => $unit['name'],
                    'symbol' => $unit['symbol'],
                    'category' => $unit['category'],
                    'decimal_places' => $unit['decimal_places'],
                    'is_active' => true,
                ],
            );
        }
    }
}
