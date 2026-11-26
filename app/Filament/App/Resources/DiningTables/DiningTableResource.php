<?php

namespace App\Filament\App\Resources\DiningTables;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\DiningTables\Pages\CreateDiningTable;
use App\Filament\App\Resources\DiningTables\Pages\EditDiningTable;
use App\Filament\App\Resources\DiningTables\Pages\ListDiningTables;
use App\Filament\App\Resources\DiningTables\Schemas\DiningTableForm;
use App\Filament\App\Resources\DiningTables\Tables\DiningTablesTable;
use App\Models\DiningTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class DiningTableResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = DiningTable::class;

    protected static ?string $slug = 'mesas';

    protected static ?string $recordTitleAttribute = 'number';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTableCells;

    protected static ?string $modelLabel = 'mesa';

    protected static ?string $pluralModelLabel = 'mesas';

    protected static ?string $navigationLabel = 'Cadastro de mesas';

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?int $navigationSort = 14;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return DiningTableForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DiningTablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDiningTables::route('/'),
            'create' => CreateDiningTable::route('/create'),
            'edit' => EditDiningTable::route('/{record}/edit'),
        ];
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Orders;
    }
}
