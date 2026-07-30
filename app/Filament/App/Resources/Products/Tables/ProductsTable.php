<?php

namespace App\Filament\App\Resources\Products\Tables;

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Services\Product\ProductService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductsTable
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
                    ->formatStateUsing(fn (ProductType $state): string => $state->label())
                    ->sortable(),
                TextColumn::make('measurementUnit.symbol')
                    ->label('Unidade')
                    ->sortable(),
                TextColumn::make('reference_unit_cost')
                    ->label('Custo unitário de referência')
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 6)
                    ->sortable(),
                TextColumn::make('sale_price')
                    ->label('Preço de venda')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('minimum_stock')
                    ->label('Estoque mínimo')
                    ->numeric(decimalPlaces: 4)
                    ->sortable(),
                TextColumn::make('current_stock')
                    ->label('Estoque atual')
                    ->state(fn (Product $record): string => $record->getCurrentStockQuantity())
                    ->numeric(decimalPlaces: 4)
                    ->toggleable(),
                TextColumn::make('average_cost')
                    ->label('Custo médio')
                    ->state(fn (Product $record): string => $record->getCurrentAverageUnitCost())
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 6)
                    ->toggleable(),
                TextColumn::make('stock_value')
                    ->label('Valor em estoque')
                    ->state(fn (Product $record): string => $record->getCurrentStockValue())
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 2)
                    ->toggleable(),
                IconColumn::make('low_stock')
                    ->label('Estoque baixo')
                    ->boolean()
                    ->state(fn (Product $record): bool => $record->tracks_stock && $record->isBelowMinimumStock())
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle')
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->toggleable(),
                IconColumn::make('tracks_stock')
                    ->label('Controla estoque')
                    ->boolean(),
                IconColumn::make('is_sellable')
                    ->label('PDV')
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
                SelectFilter::make('type')
                    ->label('Tipo')
                    ->options(ProductType::options())
                    ->native(false),
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Ativos')
                    ->falseLabel('Inativos')
                    ->placeholder('Todos'),
                TernaryFilter::make('is_sellable')
                    ->label('PDV')
                    ->trueLabel('Produtos de venda')
                    ->falseLabel('Somente consumo interno')
                    ->placeholder('Todos'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                Action::make('activate')
                    ->label('Ativar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Product $record): bool => ! $record->is_active)
                    ->action(function (Product $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ProductService::class)->changeStatus($company, $record, true);
                    }),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Desativar produto')
                    ->modalDescription('O produto será desativado, mas o histórico será preservado.')
                    ->visible(fn (Product $record): bool => $record->is_active)
                    ->action(function (Product $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ProductService::class)->changeStatus($company, $record, false);
                    }),
            ])
            ->searchable();
    }
}
