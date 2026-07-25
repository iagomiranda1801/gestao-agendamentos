<?php

namespace App\Filament\App\Resources\Appointments\RelationManagers;

use App\Models\AppointmentHistory;
use App\Models\Company;
use App\Support\CompanyDateTime;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class HistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'histories';

    protected static ?string $title = 'Histórico do agendamento';

    protected static ?string $modelLabel = 'registro';

    protected static ?string $pluralModelLabel = 'histórico';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Data e hora')
                    ->formatStateUsing(function ($state, AppointmentHistory $record): string {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return $record->created_at
                            ? CompanyDateTime::formatLocal($company, $record->created_at)
                            : '—';
                    })
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Ação')
                    ->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->placeholder('—'),
                TextColumn::make('old_status')
                    ->label('Status anterior')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—'),
                TextColumn::make('new_status')
                    ->label('Novo status')
                    ->formatStateUsing(fn ($state) => $state?->label() ?? '—'),
                TextColumn::make('old_start_at')
                    ->label('Horário anterior')
                    ->formatStateUsing(function ($state, AppointmentHistory $record): string {
                        if (! $record->old_start_at) {
                            return '—';
                        }

                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return CompanyDateTime::formatLocal($company, $record->old_start_at);
                    }),
                TextColumn::make('new_start_at')
                    ->label('Novo horário')
                    ->formatStateUsing(function ($state, AppointmentHistory $record): string {
                        if (! $record->new_start_at) {
                            return '—';
                        }

                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return CompanyDateTime::formatLocal($company, $record->new_start_at);
                    }),
                TextColumn::make('description')
                    ->label('Descrição')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
