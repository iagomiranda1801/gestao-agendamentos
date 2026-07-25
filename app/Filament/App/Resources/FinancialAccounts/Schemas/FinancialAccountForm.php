<?php

namespace App\Filament\App\Resources\FinancialAccounts\Schemas;

use App\Enums\FinancialAccountType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FinancialAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Conta financeira')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->scopedUnique(ignoreRecord: true),
                        Select::make('type')
                            ->label('Tipo')
                            ->options(FinancialAccountType::options())
                            ->required()
                            ->native(false),
                        TextInput::make('bank_name')
                            ->label('Banco')
                            ->maxLength(255),
                        TextInput::make('branch')
                            ->label('Agência')
                            ->maxLength(255),
                        TextInput::make('account_number')
                            ->label('Conta')
                            ->maxLength(255),
                        TextInput::make('pix_key')
                            ->label('Chave PIX')
                            ->maxLength(255),
                        Toggle::make('allow_negative_balance')
                            ->label('Permitir saldo negativo')
                            ->default(false),
                        Toggle::make('is_default_receipt_account')
                            ->label('Conta padrão para recebimentos')
                            ->default(false),
                        Toggle::make('is_default_expense_account')
                            ->label('Conta padrão para despesas')
                            ->default(false),
                        Textarea::make('description')
                            ->label('Descrição')
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Ativa')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
