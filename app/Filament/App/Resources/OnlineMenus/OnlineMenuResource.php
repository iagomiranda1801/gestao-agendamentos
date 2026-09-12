<?php

namespace App\Filament\App\Resources\OnlineMenus;

use App\Enums\CompanyModule;
use App\Enums\ProductType;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\OnlineMenus\Pages\CreateOnlineMenuItem;
use App\Filament\App\Resources\OnlineMenus\Pages\EditOnlineMenuItem;
use App\Filament\App\Resources\OnlineMenus\Pages\ListOnlineMenuItems;
use App\Filament\App\Resources\OnlineMenus\Schemas\OnlineMenuForm;
use App\Filament\App\Resources\OnlineMenus\Tables\OnlineMenusTable;
use App\Models\Product;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class OnlineMenuResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = Product::class;

    protected static ?string $slug = 'cardapio-online';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $modelLabel = 'item do cardápio';

    protected static ?string $pluralModelLabel = 'cardápio online';

    protected static ?string $navigationLabel = 'Cardápio online';

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?int $navigationSort = 12;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', ProductType::Sale->value);
    }

    public static function form(Schema $schema): Schema
    {
        return OnlineMenuForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OnlineMenusTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOnlineMenuItems::route('/'),
            'create' => CreateOnlineMenuItem::route('/create'),
            'edit' => EditOnlineMenuItem::route('/{record}/edit'),
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Orders;
    }
}
