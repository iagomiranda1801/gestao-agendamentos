<?php

namespace App\Filament\App\Resources\Receivables;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\Receivables\Pages\ListReceivables;
use App\Filament\App\Resources\Receivables\RelationManagers\PaymentsRelationManager;
use App\Filament\App\Resources\Receivables\Tables\ReceivablesTable;
use App\Models\Receivable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ReceivableResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = Receivable::class;

    protected static ?string $slug = 'contas-a-receber';

    protected static ?string $recordTitleAttribute = 'id';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    protected static ?string $modelLabel = 'conta a receber';

    protected static ?string $pluralModelLabel = 'contas a receber';

    protected static ?string $navigationLabel = 'Contas a receber';

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 21;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function form(Schema $schema): Schema
    {
        return $schema;
    }

    public static function table(Table $table): Table
    {
        return ReceivablesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PaymentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReceivables::route('/'),
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

    protected static function requiredCompanyModule(): CompanyModule
    {
        return CompanyModule::Finance;
    }
}
