<?php

namespace App\Filament\App\Resources\Professionals\Tables;

use App\Models\Company;
use App\Models\Professional;
use App\Services\Professional\ProfessionalService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProfessionalsTable
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
                TextColumn::make('working_hours_status')
                    ->label('Jornada')
                    ->badge()
                    ->state(fn (Professional $record): string => $record->hasConfiguredWorkingHours()
                        ? 'Jornada configurada'
                        : 'Jornada pendente')
                    ->color(fn (Professional $record): string => $record->hasConfiguredWorkingHours()
                        ? 'success'
                        : 'warning'),
                TextColumn::make('specialty')
                    ->label('Especialidade')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('phone')
                    ->label('Telefone')
                    ->searchable(['phone', 'phone_normalized'])
                    ->toggleable(),
                TextColumn::make('user.name')
                    ->label('Usuário vinculado')
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('is_bookable')
                    ->label('Agendável')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Status')
                    ->boolean()
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
                    ->label('Disponível para agendamento')
                    ->trueLabel('Sim')
                    ->falseLabel('Não')
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
                    ->visible(fn (Professional $record): bool => ! $record->is_active)
                    ->action(function (Professional $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ProfessionalService::class)->changeStatus($company, $record, true);
                    }),
                Action::make('deactivate')
                    ->label('Desativar')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Desativar profissional')
                    ->modalDescription('O profissional será desativado, mas o histórico será preservado.')
                    ->visible(fn (Professional $record): bool => $record->is_active)
                    ->action(function (Professional $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ProfessionalService::class)->changeStatus($company, $record, false);
                    }),
                Action::make('toggleBookable')
                    ->label(fn (Professional $record): string => $record->is_bookable ? 'Indisponibilizar' : 'Disponibilizar')
                    ->icon('heroicon-o-calendar')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->action(function (Professional $record): void {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        app(ProfessionalService::class)->changeBookableStatus(
                            $company,
                            $record,
                            ! $record->is_bookable,
                        );
                    }),
            ])
            ->searchable();
    }
}
