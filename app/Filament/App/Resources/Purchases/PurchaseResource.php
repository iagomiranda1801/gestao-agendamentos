<?php

namespace App\Filament\App\Resources\Purchases;

use App\Enums\StockDocumentType;
use App\Filament\App\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\App\Resources\Purchases\Pages\EditPurchase;
use App\Filament\App\Resources\Purchases\Pages\ListPurchases;
use App\Filament\App\Resources\Purchases\Schemas\PurchaseForm;
use App\Filament\App\Resources\Purchases\Tables\PurchasesTable;
use App\Models\StockDocument;
use BackedEnum;
use Closure;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PurchaseResource extends Resource
{
    protected static ?string $model = StockDocument::class;

    protected static ?string $slug = 'compras';

    protected static ?string $recordTitleAttribute = 'document_number';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static string|UnitEnum|null $navigationGroup = 'Estoque';

    protected static ?string $modelLabel = 'compra';

    protected static ?string $pluralModelLabel = 'compras';

    protected static ?string $navigationLabel = 'Compras';

    protected static ?int $navigationSort = 4;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return PurchaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchasesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('type', StockDocumentType::Purchase);
    }

    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        $record = parent::resolveRecordRouteBinding($key, $modifyQuery);

        if ($record && $record->type !== StockDocumentType::Purchase) {
            abort(404);
        }

        return $record;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchases::route('/'),
            'create' => CreatePurchase::route('/create'),
            'edit' => EditPurchase::route('/{record}/edit'),
        ];
    }
}
