<?php

namespace App\Filament\App\Resources\Suppliers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identificação')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('trade_name')
                            ->label('Nome fantasia')
                            ->maxLength(255),
                        TextInput::make('document')
                            ->label('Documento')
                            ->maxLength(255)
                            ->scopedUnique(ignoreRecord: true),
                    ])
                    ->columns(2),
                Section::make('Contato')
                    ->schema([
                        TextInput::make('phone')
                            ->label('Telefone')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('contact_name')
                            ->label('Pessoa de contato')
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Informações adicionais')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(4)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Fornecedor ativo')
                            ->default(true),
                    ]),
            ]);
    }
}
