<?php

namespace App\Filament\App\Resources\Purchases\Tables;

use App\Enums\StockDocumentStatus;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('supplier.name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('document_number')
                    ->label('Número')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('external_reference')
                    ->label('Referência externa')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (StockDocumentStatus $state): string => $state->label())
                    ->color(fn (StockDocumentStatus $state): string => match ($state) {
                        StockDocumentStatus::Draft => 'gray',
                        StockDocumentStatus::Posted => 'success',
                        StockDocumentStatus::Reversed => 'danger',
                    })
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Valor total')
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label('Criado por')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Cadastro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(StockDocumentStatus::options())
                    ->native(false),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([
                EditAction::make(),
            ])
            ->modifyQueryUsing(fn ($query) => $query->with(['supplier', 'creator']));
    }
}
