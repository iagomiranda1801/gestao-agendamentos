<?php

namespace App\Filament\App\Resources\StockMovements;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\App\Resources\StockMovements\Tables\StockMovementsTable;
use App\Models\StockMovement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class StockMovementResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = StockMovement::class;

    protected static ?string $slug = 'movimentacoes-estoque';

    protected static ?string $recordTitleAttribute = 'id';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Estoque';

    protected static ?string $modelLabel = 'movimentação de estoque';

    protected static ?string $pluralModelLabel = 'movimentações de estoque';

    protected static ?string $navigationLabel = 'Movimentações';

    protected static ?int $navigationSort = 6;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return StockMovementsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Stock;
    }
}
