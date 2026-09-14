<?php

namespace App\Filament\App\Resources\MenuCategories;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\MenuCategories\Pages\CreateMenuCategory;
use App\Filament\App\Resources\MenuCategories\Pages\EditMenuCategory;
use App\Filament\App\Resources\MenuCategories\Pages\ListMenuCategories;
use App\Filament\App\Resources\MenuCategories\Schemas\MenuCategoryForm;
use App\Filament\App\Resources\MenuCategories\Tables\MenuCategoriesTable;
use App\Models\MenuCategory;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MenuCategoryResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = MenuCategory::class;

    protected static ?string $slug = 'categorias-cardapio';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'categoria do cardápio';

    protected static ?string $pluralModelLabel = 'categorias do cardápio';

    protected static ?string $navigationLabel = 'Categorias do cardápio';

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?int $navigationSort = 13;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return MenuCategoryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MenuCategoriesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMenuCategories::route('/'),
            'create' => CreateMenuCategory::route('/create'),
            'edit' => EditMenuCategory::route('/{record}/edit'),
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
