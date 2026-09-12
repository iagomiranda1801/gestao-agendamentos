<?php

namespace App\Filament\App\Resources\OnlineMenus\Tables;

use App\Models\Company;
use App\Models\Product;
use App\Services\Product\ProductService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class OnlineMenusTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('online_order_category')
                    ->label('Categoria')
                    ->placeholder('Cardápio')
                    ->sortable(),
                TextColumn::make('sale_price')
                    ->label('Preço')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('prep_time_minutes')
                    ->label('Preparo')
                    ->suffix(' min')
                    ->placeholder('—'),
                IconColumn::make('available_for_online_order')
                    ->label('No cardápio')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('available_for_online_order')
                    ->label('No cardápio online')
                    ->trueLabel('Visíveis')
                    ->falseLabel('Ocultos')
                    ->placeholder('Todos'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                Action::make('toggleOnline')
                    ->label(fn (Product $record): string => $record->available_for_online_order ? 'Ocultar' : 'Publicar')
                    ->icon(fn (Product $record): string => $record->available_for_online_order
                        ? 'heroicon-o-eye-slash'
                        : 'heroicon-o-eye')
                    ->action(function (Product $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ProductService::class)->ensureBelongsToCompany($company, $record);
                        $record->update([
                            'available_for_online_order' => ! $record->available_for_online_order,
                        ]);
                    }),
            ])
            ->searchable();
    }
}
