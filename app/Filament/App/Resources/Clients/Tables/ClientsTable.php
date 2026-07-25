<?php

namespace App\Filament\App\Resources\Clients\Tables;

use App\Models\Client;
use App\Models\Company;
use App\Services\Client\ClientService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Telefone')
                    ->searchable(['phone', 'phone_normalized'])
                    ->sortable(),
                TextColumn::make('email')
                    ->label('E-mail')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('birth_date')
                    ->label('Data de nascimento')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),
                TextColumn::make('created_at')
                    ->label('Data de cadastro')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Status')
                    ->trueLabel('Ativos')
                    ->falseLabel('Inativos')
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
                    ->visible(fn (Client $record): bool => ! $record->is_active)
                    ->action(function (Client $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ClientService::class)->changeStatus($company, $record, true);
                    }),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Desativar cliente')
                    ->modalDescription('O cliente será desativado, mas o histórico será preservado.')
                    ->visible(fn (Client $record): bool => $record->is_active)
                    ->action(function (Client $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ClientService::class)->changeStatus($company, $record, false);
                    }),
            ])
            ->searchable()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query);
    }
}
