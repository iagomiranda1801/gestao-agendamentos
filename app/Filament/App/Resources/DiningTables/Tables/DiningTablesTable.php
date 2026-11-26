<?php

namespace App\Filament\App\Resources\DiningTables\Tables;

use App\Models\Company;
use App\Models\DiningTable;
use App\Services\Orders\DiningTableService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Validation\ValidationException;

class DiningTablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label('Número')
                    ->formatStateUsing(fn (DiningTable $record): string => $record->displayLabel())
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('sort_order')
                    ->label('Ordem')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Situação')
                    ->trueLabel('Ativas')
                    ->falseLabel('Inativas')
                    ->placeholder('Todas'),
            ])
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (DiningTable $record): bool => $record->is_active)
                    ->action(function (DiningTable $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        try {
                            app(DiningTableService::class)->deactivate($company, $record);
                        } catch (ValidationException $exception) {
                            Notification::make()
                                ->danger()
                                ->title('Não foi possível desativar')
                                ->body(collect($exception->errors())->flatten()->first())
                                ->send();
                        }
                    }),
            ]);
    }
}
