<?php

namespace Tests\Feature\MeasurementUnits;

use App\Models\MeasurementUnit;
use App\Services\Product\ProductService;
use Database\Seeders\MeasurementUnitSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MeasurementUnitTest extends TestCase
{
    public function test_seeder_creates_units_without_duplication(): void
    {
        $this->seed(MeasurementUnitSeeder::class);
        $this->seed(MeasurementUnitSeeder::class);

        $this->assertSame(5, MeasurementUnit::query()->count());
    }

    public function test_unit_code_is_unique(): void
    {
        $this->seed(MeasurementUnitSeeder::class);

        $this->expectException(QueryException::class);

        MeasurementUnit::query()->create([
            'name' => 'Duplicada',
            'symbol' => 'dup',
            'code' => 'unit',
            'category' => 'count',
            'decimal_places' => 0,
            'is_active' => true,
        ]);
    }

    public function test_inactive_unit_cannot_be_used_in_new_product(): void
    {
        $company = $this->createCompany();
        $unit = MeasurementUnit::factory()->inactive()->create();

        $this->expectException(ValidationException::class);

        app(ProductService::class)->create($company, [
            'name' => 'Produto Teste',
            'measurement_unit_id' => $unit->getKey(),
            'type' => 'consumable',
            'reference_unit_cost' => 1,
            'minimum_stock' => 0,
            'tracks_stock' => true,
            'is_active' => true,
        ]);
    }
}
