<?php

namespace App\Filament\App\Resources\DiningTables\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DiningTableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Mesa')->schema([
                TextInput::make('number')
                    ->label('Número')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(999)
                    ->required(),
                TextInput::make('name')
                    ->label('Nome')
                    ->maxLength(80)
                    ->helperText('Opcional. Ex.: Varanda, Balcão, Salão.'),
                TextInput::make('sort_order')
                    ->label('Ordem no mapa')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Menor número aparece primeiro na tela de comandas.'),
                Toggle::make('is_active')
                    ->label('Ativa')
                    ->default(true),
            ])->columns(2),
        ]);
    }
}
