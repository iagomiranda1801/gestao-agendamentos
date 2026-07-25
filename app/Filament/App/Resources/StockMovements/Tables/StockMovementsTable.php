<?php

namespace App\Filament\App\Resources\StockMovements\Tables;

use App\Enums\StockDocumentType;
use App\Enums\StockMovementDirection;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class StockMovementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('product.name')
                    ->label('Produto')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('document.type')
                    ->label('Tipo de documento')
                    ->formatStateUsing(fn (?StockDocumentType $state): string => $state?->label() ?? '—'),
                TextColumn::make('direction')
                    ->label('Direção')
                    ->badge()
                    ->formatStateUsing(fn (StockMovementDirection $state): string => $state->label())
                    ->color(fn (StockMovementDirection $state): string => match ($state) {
                        StockMovementDirection::Inbound => 'success',
                        StockMovementDirection::Outbound => 'danger',
                    }),
                TextColumn::make('quantity')
                    ->label('Quantidade')
                    ->numeric(decimalPlaces: 4)
                    ->sortable(),
                TextColumn::make('unit_cost')
                    ->label('Custo unitário')
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 6)
                    ->sortable(),
                TextColumn::make('total_cost')
                    ->label('Custo total')
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('quantity_before')
                    ->label('Qtd. anterior')
                    ->numeric(decimalPlaces: 4)
                    ->toggleable(),
                TextColumn::make('quantity_after')
                    ->label('Qtd. posterior')
                    ->numeric(decimalPlaces: 4)
                    ->toggleable(),
                TextColumn::make('creator.name')
                    ->label('Responsável')
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('occurred_at')
                    ->label('Período')
                    ->schema([
                        DatePicker::make('from')
                            ->label('De')
                            ->native(false),
                        DatePicker::make('until')
                            ->label('Até')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '<=', $date),
                            );
                    }),
                SelectFilter::make('product_id')
                    ->label('Produto')
                    ->relationship('product', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
                SelectFilter::make('document_type')
                    ->label('Tipo de documento')
                    ->options(StockDocumentType::options())
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return $query;
                        }

                        return $query->whereHas(
                            'document',
                            fn (Builder $query): Builder => $query->where('type', $value),
                        );
                    })
                    ->native(false),
                SelectFilter::make('direction')
                    ->label('Direção')
                    ->options(StockMovementDirection::options())
                    ->native(false),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->recordActions([])
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['product', 'document', 'creator']));
    }
}
