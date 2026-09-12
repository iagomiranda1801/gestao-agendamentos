<?php

namespace App\Filament\App\Resources\Orders\RelationManagers;

use App\Enums\OrderStatus;
use App\Models\Company;
use App\Models\OrderStatusHistory;
use App\Support\CompanyDateTime;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatusHistoriesRelationManager extends RelationManager
{
    protected static string $relationship = 'statusHistories';

    protected static ?string $title = 'Histórico de status';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Quando')
                    ->formatStateUsing(function (OrderStatusHistory $record): string {
                        /** @var Company $company */
                        $company = Filament::getTenant();

                        return $record->created_at
                            ? CompanyDateTime::formatLocal($company, $record->created_at)
                            : '—';
                    }),
                TextColumn::make('from_status')
                    ->label('De')
                    ->formatStateUsing(fn (?OrderStatus $state): string => $state?->label() ?? '—'),
                TextColumn::make('to_status')
                    ->label('Para')
                    ->formatStateUsing(fn (?OrderStatus $state): string => $state?->label() ?? '—'),
                TextColumn::make('user.name')
                    ->label('Usuário')
                    ->placeholder('Cliente / sistema'),
            ])
            ->defaultSort('created_at', 'desc')
            ->headerActions([])
            ->recordActions([])
            ->paginated([10, 25]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
