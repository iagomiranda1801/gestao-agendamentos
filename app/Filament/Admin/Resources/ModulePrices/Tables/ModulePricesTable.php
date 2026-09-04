<?php

namespace App\Filament\Admin\Resources\ModulePrices\Tables;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Services\Company\CompanySubscriptionService;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ModulePricesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('module')
                    ->label('Módulo')
                    ->formatStateUsing(fn (CompanyModule $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('interval')
                    ->label('Ciclo')
                    ->badge()
                    ->formatStateUsing(fn (BillingInterval $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('price_cents')
                    ->label('Preço')
                    ->formatStateUsing(fn (int $state): string => app(CompanySubscriptionService::class)->formatReais($state))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('interval')
                    ->label('Ciclo')
                    ->options(BillingInterval::options()),
                SelectFilter::make('module')
                    ->label('Módulo')
                    ->options(CompanyModule::options()),
            ])
            ->defaultSort('module')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
