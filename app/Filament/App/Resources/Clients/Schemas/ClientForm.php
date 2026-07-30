<?php

namespace App\Filament\App\Resources\Clients\Schemas;

use App\Support\Cpf;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados pessoais')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('WhatsApp / Telefone')
                            ->tel()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('document')
                            ->label('CPF')
                            ->mask('999.999.999-99')
                            ->maxLength(14)
                            ->dehydrateStateUsing(fn (?string $state): ?string => Cpf::normalize($state))
                            ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                                if (filled($value) && ! Cpf::isValid((string) $value)) {
                                    $fail('Informe um CPF válido.');
                                }
                            })
                            ->scopedUnique(ignoreRecord: true),
                        DatePicker::make('birth_date')
                            ->label('Data de nascimento')
                            ->maxDate(now())
                            ->native(false),
                    ])
                    ->columns(2),
                Section::make('Informações adicionais')
                    ->schema([
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(4)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Cliente ativo')
                            ->default(true),
                        Toggle::make('whatsapp_marketing_opt_in')
                            ->label('Aceita campanhas no WhatsApp')
                            ->helperText('Use somente quando o cliente autorizou receber mensagens promocionais.')
                            ->default(false),
                    ]),
            ]);
    }
}
