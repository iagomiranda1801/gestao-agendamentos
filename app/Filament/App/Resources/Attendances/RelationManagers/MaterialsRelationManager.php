<?php

namespace App\Filament\App\Resources\Attendances\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MaterialsRelationManager extends RelationManager
{
    protected static string $relationship = 'materials';

    protected static ?string $title = 'Materiais utilizados';

    protected static ?string $modelLabel = 'material';

    protected static ?string $pluralModelLabel = 'materiais';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('product_name_snapshot')
                    ->label('Produto')
                    ->searchable(),
                TextColumn::make('planned_quantity')
                    ->label('Qtd. planejada')
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('quantity')
                    ->label('Qtd. utilizada')
                    ->numeric(decimalPlaces: 4),
                TextColumn::make('unit_cost_snapshot')
                    ->label('Custo unitário')
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 6),
                TextColumn::make('total_cost')
                    ->label('Custo total')
                    ->money('BRL', locale: 'pt_BR', decimalPlaces: 2),
                IconColumn::make('tracks_stock_snapshot')
                    ->label('Controla estoque')
                    ->boolean(),
                TextColumn::make('notes')
                    ->label('Observações')
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
