<?php

namespace App\Filament\App\Resources\FinancialAccounts\Tables;

use App\Models\Company;
use App\Models\FinancialAccount;
use App\Services\Financial\FinancialAccountService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FinancialAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => $state?->label())
                    ->sortable(),
                TextColumn::make('balance.current_balance')
                    ->label('Saldo atual')
                    ->money('BRL')
                    ->sortable(),
                IconColumn::make('is_default_receipt_account')
                    ->label('Conta padrão de recebimento')
                    ->boolean(),
                IconColumn::make('is_default_expense_account')
                    ->label('Conta padrão de despesas')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Ativas')
                    ->falseLabel('Inativas')
                    ->placeholder('Todas'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                Action::make('activate')
                    ->label('Ativar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (FinancialAccount $record): bool => ! $record->is_active)
                    ->action(function (FinancialAccount $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(FinancialAccountService::class)->activate($company, $record);
                    }),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (FinancialAccount $record): bool => $record->is_active)
                    ->action(function (FinancialAccount $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(FinancialAccountService::class)->deactivate($company, $record);
                    }),
            ])
            ->searchable();
    }
}
