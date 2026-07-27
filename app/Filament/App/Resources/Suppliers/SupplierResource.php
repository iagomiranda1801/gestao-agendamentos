<?php

namespace App\Filament\App\Resources\Suppliers;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\Suppliers\Pages\CreateSupplier;
use App\Filament\App\Resources\Suppliers\Pages\EditSupplier;
use App\Filament\App\Resources\Suppliers\Pages\ListSuppliers;
use App\Filament\App\Resources\Suppliers\Schemas\SupplierForm;
use App\Filament\App\Resources\Suppliers\Tables\SuppliersTable;
use App\Models\Supplier;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class SupplierResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = Supplier::class;

    protected static ?string $slug = 'fornecedores';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static string|UnitEnum|null $navigationGroup = 'Estoque';

    protected static ?string $modelLabel = 'fornecedor';

    protected static ?string $pluralModelLabel = 'fornecedores';

    protected static ?string $navigationLabel = 'Fornecedores';

    protected static ?int $navigationSort = 3;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return SupplierForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SuppliersTable::configure($table);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'trade_name', 'document', 'phone', 'phone_normalized', 'email', 'contact_name'];
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSuppliers::route('/'),
            'create' => CreateSupplier::route('/create'),
            'edit' => EditSupplier::route('/{record}/edit'),
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Stock;
    }
}
