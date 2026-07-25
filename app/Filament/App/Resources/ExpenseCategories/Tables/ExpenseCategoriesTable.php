<?php

namespace App\Filament\App\Resources\ExpenseCategories\Tables;

use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Services\Financial\ExpenseCategoryService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ExpenseCategoriesTable
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
                TextColumn::make('parent.name')
                    ->label('Categoria pai')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('affects_managerial_result')
                    ->label('Afeta resultado')
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
            ->defaultSort('sort_order')
            ->recordActions([
                EditAction::make(),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (ExpenseCategory $record): bool => $record->is_active && ! $record->is_system)
                    ->action(function (ExpenseCategory $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ExpenseCategoryService::class)->deactivate($company, $record);
                    }),
            ])
            ->searchable();
    }
}
