<?php

namespace App\Filament\App\Resources\Orders\RelationManagers;

use App\Support\Money;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Itens';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quantity')->label('Qtd'),
                TextColumn::make('name')->label('Item'),
                TextColumn::make('unit_price_cents')
                    ->label('Unitário')
                    ->formatStateUsing(fn (int $state): string => Money::formatCents($state)),
                TextColumn::make('line_total_cents')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => Money::formatCents($state)),
                TextColumn::make('notes')->label('Obs.')->placeholder('—'),
            ])
            ->headerActions([])
            ->recordActions([])
            ->paginated(false);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
