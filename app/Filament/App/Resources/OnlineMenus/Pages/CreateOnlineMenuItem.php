<?php

namespace App\Filament\App\Resources\OnlineMenus\Pages;

use App\Enums\MeasurementUnitCategory;
use App\Enums\ProductType;
use App\Filament\App\Resources\OnlineMenus\OnlineMenuResource;
use App\Filament\App\Resources\Pages\CreateRecord;
use App\Models\Company;
use App\Models\MeasurementUnit;
use App\Services\Product\ProductService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class CreateOnlineMenuItem extends CreateRecord
{
    protected static string $resource = OnlineMenuResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ProductService::class)->create($company, [
            ...$data,
            'type' => ProductType::Sale->value,
            'measurement_unit_id' => self::defaultUnitId(),
            'reference_unit_cost' => 0,
            'minimum_stock' => 0,
            'tracks_stock' => false,
            'available_for_online_order' => (bool) ($data['available_for_online_order'] ?? true),
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);
    }

    protected static function defaultUnitId(): int
    {
        $unit = MeasurementUnit::query()
            ->active()
            ->where('code', 'unit')
            ->first()
            ?? MeasurementUnit::query()->active()->orderBy('name')->first();

        if ($unit) {
            return (int) $unit->getKey();
        }

        $created = MeasurementUnit::query()->create([
            'code' => 'unit',
            'name' => 'Unidade',
            'symbol' => 'un',
            'category' => MeasurementUnitCategory::Count,
            'decimal_places' => 0,
            'is_active' => true,
        ]);

        return (int) $created->getKey();
    }
}
