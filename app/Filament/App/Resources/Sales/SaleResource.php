<?php

namespace App\Filament\App\Resources\Sales;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\Sales\Pages\ListSales;
use App\Filament\App\Resources\Sales\Tables\SalesTable;
use App\Models\Sale;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SaleResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = Sale::class;

    protected static ?string $slug = 'vendas';

    protected static ?string $recordTitleAttribute = 'id';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?string $modelLabel = 'venda';

    protected static ?string $pluralModelLabel = 'vendas';

    protected static ?string $navigationLabel = 'Vendas';

    protected static string|UnitEnum|null $navigationGroup = 'Vendas';

    protected static ?int $navigationSort = 31;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return SalesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSales::route('/'),
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Sales;
    }
}
