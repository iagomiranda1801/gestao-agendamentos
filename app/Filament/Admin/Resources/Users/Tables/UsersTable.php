<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_super_admin')
                    ->label('Superadmin')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Ativo')
                    ->boolean(),
                TextColumn::make('companies_count')
                    ->label('Empresas')
                    ->counts('companies')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Ativos')
                    ->falseLabel('Inativos')
                    ->placeholder('Todos'),
                TernaryFilter::make('is_super_admin')
                    ->label('Superadministrador')
                    ->trueLabel('Sim')
                    ->falseLabel('Não')
                    ->placeholder('Todos'),
            ])
            ->defaultSort('name')
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->requiresConfirmation(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->requiresConfirmation(),
                ]),
            ]);
    }
}
