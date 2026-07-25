<?php

namespace App\Filament\App\Resources\Payables;

use App\Filament\App\Resources\Payables\Pages\ListPayables;
use App\Filament\App\Resources\Payables\Tables\PayablesTable;
use App\Models\Payable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PayableResource extends Resource
{
    protected static ?string $model = Payable::class;

    protected static ?string $slug = 'contas-a-pagar';

    protected static ?string $recordTitleAttribute = 'description';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $modelLabel = 'conta a pagar';

    protected static ?string $pluralModelLabel = 'contas a pagar';

    protected static ?string $navigationLabel = 'Contas a pagar';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 22;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return PayablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayables::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
