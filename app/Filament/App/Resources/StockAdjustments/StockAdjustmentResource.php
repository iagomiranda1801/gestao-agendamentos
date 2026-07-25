<?php

namespace App\Filament\App\Resources\StockAdjustments;

use App\Enums\StockDocumentType;
use App\Filament\App\Resources\StockAdjustments\Pages\CreateStockAdjustment;
use App\Filament\App\Resources\StockAdjustments\Pages\EditStockAdjustment;
use App\Filament\App\Resources\StockAdjustments\Pages\ListStockAdjustments;
use App\Filament\App\Resources\StockAdjustments\Schemas\StockAdjustmentForm;
use App\Filament\App\Resources\StockAdjustments\Tables\StockAdjustmentsTable;
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

class StockAdjustmentResource extends Resource
{
    protected static ?string $model = StockDocument::class;

    protected static ?string $slug = 'ajustes-estoque';

    protected static ?string $recordTitleAttribute = 'document_number';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Estoque';

    protected static ?string $modelLabel = 'ajuste de estoque';

    protected static ?string $pluralModelLabel = 'ajustes de estoque';

    protected static ?string $navigationLabel = 'Ajustes de estoque';

    protected static ?int $navigationSort = 5;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return StockAdjustmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return StockAdjustmentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereIn('type', [
                StockDocumentType::OpeningBalance,
                StockDocumentType::ManualEntry,
                StockDocumentType::ManualExit,
                StockDocumentType::Loss,
                StockDocumentType::InventoryCount,
            ]);
    }

    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        $record = parent::resolveRecordRouteBinding($key, $modifyQuery);

        if ($record && ! in_array($record->type, [
            StockDocumentType::OpeningBalance,
            StockDocumentType::ManualEntry,
            StockDocumentType::ManualExit,
            StockDocumentType::Loss,
            StockDocumentType::InventoryCount,
        ], true)) {
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
            'index' => ListStockAdjustments::route('/'),
            'create' => CreateStockAdjustment::route('/create'),
            'edit' => EditStockAdjustment::route('/{record}/edit'),
        ];
    }
}
