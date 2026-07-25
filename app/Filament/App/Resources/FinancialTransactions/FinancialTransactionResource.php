<?php

namespace App\Filament\App\Resources\FinancialTransactions;

use App\Enums\FinancialTransactionDirection;
use App\Enums\FinancialTransactionType;
use App\Filament\App\Resources\FinancialTransactions\Pages\ListFinancialTransactions;
use App\Models\FinancialTransaction;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class FinancialTransactionResource extends Resource
{
    protected static ?string $model = FinancialTransaction::class;

    protected static ?string $slug = 'transacoes-financeiras';

    protected static ?string $navigationLabel = 'Transações';

    protected static ?string $modelLabel = 'transação financeira';

    protected static ?string $pluralModelLabel = 'transações financeiras';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static string|UnitEnum|null $navigationGroup = 'Financeiro';

    protected static ?int $navigationSort = 16;

    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['financialAccount', 'creator', 'cashSession', 'source']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('financialAccount.name')
                    ->label('Conta')
                    ->sortable(),
                TextColumn::make('direction')
                    ->label('Direção')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->limit(40)
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('source_type')
                    ->label('Origem')
                    ->formatStateUsing(fn (?string $state): string => $state ? class_basename($state) : '—')
                    ->toggleable(),
                TextColumn::make('creator.name')
                    ->label('Usuário')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('cashSession.id')
                    ->label('Sessão de caixa')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('reversed_at')
                    ->label('Estornada em')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->schema([
                        DatePicker::make('from')
                            ->label('De'),
                        DatePicker::make('until')
                            ->label('Até'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '>=', $date))
                            ->when($data['until'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '<=', $date));
                    }),
                SelectFilter::make('financial_account_id')
                    ->label('Conta')
                    ->relationship('financialAccount', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('direction')
                    ->label('Direção')
                    ->options(collect(FinancialTransactionDirection::cases())
                        ->mapWithKeys(fn (FinancialTransactionDirection $direction) => [$direction->value => $direction->label()])
                        ->all())
                    ->native(false),
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(FinancialTransactionType::options())
                    ->native(false),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialTransactions::route('/'),
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
