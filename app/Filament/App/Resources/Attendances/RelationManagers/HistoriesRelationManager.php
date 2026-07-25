<?php

namespace App\Filament\App\Resources\Attendances\RelationManagers;

use App\Models\AttendanceHistory;
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

    protected static ?string $title = 'Histórico do atendimento';

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
                    ->formatStateUsing(function ($state, AttendanceHistory $record): string {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return $record->created_at
                            ? CompanyDateTime::formatLocal($company, $record->created_at)
                            : '—';
                    })
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->placeholder('—'),
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
