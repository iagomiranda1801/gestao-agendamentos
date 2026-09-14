<?php

namespace App\Filament\App\Resources\OnlineMenus\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class OnlineMenuForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Item do cardápio')->schema([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(255),
                TextInput::make('sale_price')
                    ->label('Preço')
                    ->helperText('Usado quando o item não tem tamanhos. Com tamanhos, o pedido usa o preço de cada tamanho.')
                    ->numeric()
                    ->prefix('R$')
                    ->step(0.01)
                    ->minValue(0.01)
                    ->required(),
                OnlineMenuFields::categorySelect(),
                TextInput::make('prep_time_minutes')
                    ->label('Tempo de preparo (min)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(240),
                Toggle::make('available_for_online_order')
                    ->label('Visível no cardápio online')
                    ->default(true),
                Toggle::make('is_active')
                    ->label('Item ativo')
                    ->default(true),
                Textarea::make('description')
                    ->label('Descrição')
                    ->rows(3)
                    ->columnSpanFull(),
                OnlineMenuFields::variantsRepeater(),
            ])->columns(2),
        ]);
    }
}
