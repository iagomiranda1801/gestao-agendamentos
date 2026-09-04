<?php

namespace App\Filament\Admin\Resources\ModulePrices;

use App\Filament\Admin\Resources\ModulePrices\Pages\EditModulePrice;
use App\Filament\Admin\Resources\ModulePrices\Pages\ListModulePrices;
use App\Filament\Admin\Resources\ModulePrices\Schemas\ModulePriceForm;
use App\Filament\Admin\Resources\ModulePrices\Tables\ModulePricesTable;
use App\Models\ModulePrice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ModulePriceResource extends Resource
{
    protected static ?string $model = ModulePrice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $modelLabel = 'preço de módulo';

    protected static ?string $pluralModelLabel = 'preços dos módulos';

    protected static ?string $navigationLabel = 'Preços dos módulos';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return ModulePriceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ModulePricesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListModulePrices::route('/'),
            'edit' => EditModulePrice::route('/{record}/edit'),
        ];
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }
}
