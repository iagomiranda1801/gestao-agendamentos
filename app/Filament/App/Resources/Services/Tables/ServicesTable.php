<?php

namespace App\Filament\App\Resources\Services\Tables;

use App\Models\Company;
use App\Models\Service;
use App\Services\Service\ServiceCatalogService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('Ordem')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('price')
                    ->label('Preço')
                    ->money('BRL', locale: 'pt_BR')
                    ->sortable(),
                TextColumn::make('duration_minutes')
                    ->label('Duração')
                    ->formatStateUsing(fn (int $state): string => "{$state} min")
                    ->sortable(),
                TextColumn::make('estimated_material_cost')
                    ->label('Custo estimado de materiais')
                    ->state(fn (Service $record): string => $record->getEstimatedMaterialCost())
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 2),
                TextColumn::make('estimated_gross_margin')
                    ->label('Margem estimada')
                    ->state(fn (Service $record): string => $record->getEstimatedGrossMargin())
                    ->money('BRL', locale: 'pt_BR'),
                IconColumn::make('is_online_booking_enabled')
                    ->label('Agendamento online')
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
                    ->trueLabel('Ativos')
                    ->falseLabel('Inativos')
                    ->placeholder('Todos'),
                TernaryFilter::make('is_bookable')
                    ->label('Agendáveis')
                    ->trueLabel('Agendáveis')
                    ->falseLabel('Não agendáveis')
                    ->placeholder('Todos'),
                TernaryFilter::make('is_online_booking_enabled')
                    ->label('Agendamento online')
                    ->trueLabel('Online habilitado')
                    ->falseLabel('Online desabilitado')
                    ->placeholder('Todos'),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
                Action::make('activate')
                    ->label('Ativar')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Service $record): bool => ! $record->is_active)
                    ->action(function (Service $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ServiceCatalogService::class)->changeStatus($company, $record, true);
                    }),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Desativar serviço')
                    ->modalDescription('O serviço será desativado, mas o histórico será preservado.')
                    ->visible(fn (Service $record): bool => $record->is_active)
                    ->action(function (Service $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ServiceCatalogService::class)->changeStatus($company, $record, false);
                    }),
            ])
            ->searchable();
    }
}
