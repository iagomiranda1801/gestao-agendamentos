<?php

namespace App\Filament\App\Resources\FinancialAccounts;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\FinancialAccounts\Pages\CreateFinancialAccount;
use App\Filament\App\Resources\FinancialAccounts\Pages\EditFinancialAccount;
use App\Filament\App\Resources\FinancialAccounts\Pages\ListFinancialAccounts;
use App\Filament\App\Resources\FinancialAccounts\Schemas\FinancialAccountForm;
use App\Filament\App\Resources\FinancialAccounts\Tables\FinancialAccountsTable;
use App\Models\FinancialAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FinancialAccountResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = FinancialAccount::class;

    protected static ?string $slug = 'contas-financeiras';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    protected static string|UnitEnum|null $navigationGroup = 'Caixa';

    protected static ?string $modelLabel = 'conta financeira';

    protected static ?string $pluralModelLabel = 'contas financeiras';

    protected static ?string $navigationLabel = 'Contas financeiras';

    protected static ?int $navigationSort = 3;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('balance');
    }

    public static function form(Schema $schema): Schema
    {
        return FinancialAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinancialAccountsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialAccounts::route('/'),
            'create' => CreateFinancialAccount::route('/create'),
            'edit' => EditFinancialAccount::route('/{record}/edit'),
        ];
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
