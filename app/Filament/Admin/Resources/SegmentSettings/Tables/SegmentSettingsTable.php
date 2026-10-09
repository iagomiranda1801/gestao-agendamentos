<?php

namespace App\Filament\Admin\Resources\SegmentSettings\Tables;

use App\Models\SegmentSetting;
use App\Support\Segment;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ColorColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SegmentSettingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('segment')
                    ->label('Produto')
                    ->formatStateUsing(fn (string $state, SegmentSetting $record): string => $record->label())
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable(),
                ColorColumn::make('primary_color')
                    ->label('Cor')
                    ->state(fn (SegmentSetting $record): string => Segment::themeColor($record->segment)),
                TextColumn::make('domain')
                    ->label('Domínio')
                    ->state(fn (SegmentSetting $record): string => (string) Segment::get($record->segment, 'domain')),
                TextColumn::make('status')
                    ->label('Situação')
                    ->badge()
                    ->state(fn (SegmentSetting $record): string => Segment::isEnabled($record->segment) ? 'Ligado' : 'Desligado')
                    ->color(fn (SegmentSetting $record): string => Segment::isEnabled($record->segment) ? 'success' : 'gray'),
            ])
            ->defaultSort('segment')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
