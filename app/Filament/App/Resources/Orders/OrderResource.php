<?php

namespace App\Filament\App\Resources\Orders;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\Orders\Pages\ListOrders;
use App\Filament\App\Resources\Orders\Pages\ViewOrder;
use App\Filament\App\Resources\Orders\RelationManagers\ItemsRelationManager;
use App\Filament\App\Resources\Orders\RelationManagers\StatusHistoriesRelationManager;
use App\Filament\App\Resources\Orders\Schemas\OrderInfolist;
use App\Filament\App\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class OrderResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = Order::class;

    protected static ?string $slug = 'pedidos';

    protected static ?string $recordTitleAttribute = 'public_code';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $modelLabel = 'pedido';

    protected static ?string $pluralModelLabel = 'pedidos';

    protected static ?string $navigationLabel = 'Histórico de pedidos';

    protected static string|UnitEnum|null $navigationGroup = 'Pedidos';

    protected static ?int $navigationSort = 11;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function shouldRegisterNavigation(): bool
    {
        return static::tenantHasRequiredModule() && static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function infolist(Schema $schema): Schema
    {
        return OrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ItemsRelationManager::class,
            StatusHistoriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
            'view' => ViewOrder::route('/{record}'),
        ];
    }

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Orders;
    }
}
