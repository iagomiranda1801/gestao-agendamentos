<?php

namespace App\Filament\App\Resources\MenuCategories\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class MenuCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Categoria')->schema([
                TextInput::make('name')
                    ->label('Nome')
                    ->required()
                    ->maxLength(80),
                TextInput::make('sort_order')
                    ->label('Ordem')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Menor número aparece primeiro no cardápio público.'),
                Toggle::make('is_active')
                    ->label('Ativa')
                    ->default(true),
            ])->columns(2),
        ]);
    }
}
