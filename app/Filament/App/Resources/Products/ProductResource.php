<?php

namespace App\Filament\App\Resources\Products;

use App\Filament\App\Resources\Products\Pages\CreateProduct;
use App\Filament\App\Resources\Products\Pages\EditProduct;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\Schemas\ProductForm;
use App\Filament\App\Resources\Products\Tables\ProductsTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $slug = 'produtos';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $modelLabel = 'produto';

    protected static ?string $pluralModelLabel = 'produtos e materiais';

    protected static ?string $navigationLabel = 'Produtos e materiais';

    protected static string|UnitEnum|null $navigationGroup = 'Estoque';

    protected static ?int $navigationSort = 1;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('inventoryBalance');
    }

    public static function form(Schema $schema): Schema
    {
        return ProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProductsTable::configure($table);
    }

    /**
     * @return list<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'sku'];
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
