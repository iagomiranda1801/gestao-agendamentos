<?php

namespace App\Filament\App\Resources\Sales\Tables;

use App\Enums\SaleOrigin;
use App\Enums\SaleStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SalesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('sold_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->placeholder('Consumidor final')
                    ->searchable(),
                TextColumn::make('seller.name')
                    ->label('Vendedor')
                    ->toggleable(),
                TextColumn::make('origin')
                    ->label('Origem')
                    ->formatStateUsing(fn (SaleOrigin $state): string => $state->label())
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (SaleStatus $state): string => $state->label())
                    ->badge()
                    ->color(fn (SaleStatus $state): string => match ($state) {
                        SaleStatus::Draft => 'gray',
                        SaleStatus::Completed => 'warning',
                        SaleStatus::Partial => 'info',
                        SaleStatus::Paid => 'success',
                        SaleStatus::Cancelled => 'danger',
                    }),
                TextColumn::make('final_amount')
                    ->label('Total')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label('Pago')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('outstanding_amount')
                    ->label('Aberto')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label('Itens')
                    ->counts('items')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(SaleStatus::options()),
                SelectFilter::make('origin')
                    ->label('Origem')
                    ->options(SaleOrigin::options()),
            ])
            ->defaultSort('sold_at', 'desc')
            ->recordActions([])
            ->searchable();
    }
}
