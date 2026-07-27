<?php

namespace App\Filament\App\Resources\Transfers;

use App\Enums\CompanyModule;
use App\Filament\App\Concerns\RequiresCompanyModuleResource;
use App\Filament\App\Resources\Transfers\Pages\ListTransfers;
use App\Models\FinancialTransfer;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class TransferResource extends Resource
{
    use RequiresCompanyModuleResource;

    protected static ?string $model = FinancialTransfer::class;

    protected static ?string $slug = 'transferencias';

    protected static ?string $navigationLabel = 'Transferências';

    protected static ?string $modelLabel = 'transferência';

    protected static ?string $pluralModelLabel = 'transferências';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Caixa';

    protected static ?int $navigationSort = 2;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['fromFinancialAccount', 'toFinancialAccount', 'creator']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('fromFinancialAccount.name')
                    ->label('Origem'),
                TextColumn::make('toFinancialAccount.name')
                    ->label('Destino'),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL'),
                TextColumn::make('fee_amount')
                    ->label('Taxa')
                    ->money('BRL'),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(40),
                TextColumn::make('reversed_at')
                    ->label('Estornada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTransfers::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', FinancialTransfer::class) ?? false;
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
