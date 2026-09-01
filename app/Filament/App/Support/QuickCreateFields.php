<?php

namespace App\Filament\App\Support;

use App\Enums\CompanyModule;
use App\Enums\MeasurementUnitCategory;
use App\Enums\ProductType;
use App\Models\Company;
use App\Models\MeasurementUnit;
use App\Models\Product;
use App\Models\Service;
use App\Services\Client\ClientService;
use App\Services\Company\CompanyModuleService;
use App\Services\Product\ProductService;
use App\Services\Service\ServiceCatalogService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Validation\ValidationException;

final class QuickCreateFields
{
    /**
     * @return list<TextInput>
     */
    public static function clientForm(): array
    {
        $fields = [
            TextInput::make('name')->label('Nome')->required()->maxLength(255),
            TextInput::make('phone')->label('Telefone')->required()->maxLength(30),
            TextInput::make('email')->label('E-mail')->email()->maxLength(255),
        ];

        if (self::tenant()?->isCarWash()) {
            $fields[] = TextInput::make('vehicle_plate')
                ->label('Placa')
                ->maxLength(8)
                ->helperText('Opcional. Formato ABC-1234 ou ABC1D23.');
        }

        return $fields;
    }

    /**
     * @return list<TextInput>
     */
    public static function serviceForm(): array
    {
        return [
            TextInput::make('name')->label('Nome')->required()->maxLength(255),
            TextInput::make('price')
                ->label('Preço')
                ->numeric()
                ->prefix('R$')
                ->step(0.01)
                ->minValue(0)
                ->default('0.00')
                ->required(),
            TextInput::make('duration_minutes')
                ->label('Duração')
                ->numeric()
                ->suffix('min')
                ->minValue(1)
                ->default(30)
                ->required(),
        ];
    }

    /**
     * @return list<TextInput>
     */
    public static function productForm(): array
    {
        return [
            TextInput::make('name')->label('Nome')->required()->maxLength(255),
            TextInput::make('sale_price')
                ->label('Preço de venda')
                ->numeric()
                ->prefix('R$')
                ->step(0.01)
                ->minValue(0.01)
                ->required(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createClient(array $data): int
    {
        return app(ClientService::class)
            ->create(self::requireTenant(), $data)
            ->getKey();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createService(array $data): int
    {
        $company = self::requireTenant();
        $sellable = app(CompanyModuleService::class)->hasModule($company, CompanyModule::Sales);

        return app(ServiceCatalogService::class)
            ->create($company, [
                'name' => $data['name'],
                'price' => $data['price'] ?? 0,
                'duration_minutes' => (int) ($data['duration_minutes'] ?? 30),
                'is_bookable' => true,
                'is_sellable' => $sellable,
                'is_online_booking_enabled' => false,
                'is_active' => true,
            ])
            ->getKey();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createProduct(array $data): int
    {
        return app(ProductService::class)
            ->create(self::requireTenant(), [
                'name' => $data['name'],
                'type' => ProductType::Sale->value,
                'sale_price' => $data['sale_price'],
                'measurement_unit_id' => self::defaultMeasurementUnitId(),
                'is_sellable' => true,
                'is_active' => true,
                'tracks_stock' => false,
            ])
            ->getKey();
    }

    public static function applyClientCreate(Select $select): Select
    {
        return $select
            ->createOptionForm(self::clientForm())
            ->createOptionUsing(fn (array $data): int => self::createClient($data));
    }

    public static function applyServiceCreate(Select $select, bool $fillUnitPrice = false): Select
    {
        $select = $select->createOptionForm(self::serviceForm());

        if (! $fillUnitPrice) {
            return $select->createOptionUsing(fn (array $data): int => self::createService($data));
        }

        return $select->createOptionUsing(function (array $data, Set $set): int {
            $id = self::createService($data);
            $service = Service::query()->find($id);

            if ($service !== null) {
                $set('unit_price', (string) $service->price);
            }

            return $id;
        });
    }

    public static function applyProductCreate(Select $select, bool $fillUnitPrice = false): Select
    {
        $select = $select->createOptionForm(self::productForm());

        if (! $fillUnitPrice) {
            return $select->createOptionUsing(fn (array $data): int => self::createProduct($data));
        }

        return $select->createOptionUsing(function (array $data, Set $set): int {
            $id = self::createProduct($data);
            $product = Product::query()->find($id);

            if ($product !== null) {
                $set('unit_price', (string) $product->sale_price);
            }

            return $id;
        });
    }

    protected static function defaultMeasurementUnitId(): int
    {
        $unit = MeasurementUnit::query()
            ->where('is_active', true)
            ->where('code', 'unit')
            ->first()
            ?? MeasurementUnit::query()->where('is_active', true)->orderBy('id')->first();

        if ($unit !== null) {
            return (int) $unit->getKey();
        }

        return (int) MeasurementUnit::query()->create([
            'name' => 'Unidade',
            'symbol' => 'un',
            'code' => 'unit',
            'category' => MeasurementUnitCategory::Count,
            'decimal_places' => 0,
            'is_active' => true,
        ])->getKey();
    }

    protected static function tenant(): ?Company
    {
        $company = Filament::getTenant();

        return $company instanceof Company ? $company : null;
    }

    protected static function requireTenant(): Company
    {
        $company = self::tenant();

        if ($company === null) {
            throw ValidationException::withMessages([
                'name' => 'Selecione uma empresa para cadastrar.',
            ]);
        }

        return $company;
    }
}
