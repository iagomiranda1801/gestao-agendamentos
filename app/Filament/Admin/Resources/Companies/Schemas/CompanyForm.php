<?php

namespace App\Filament\Admin\Resources\Companies\Schemas;

use App\Models\Company;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados da empresa')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(function (?string $state, callable $set, string $operation): void {
                                if ($operation !== 'create') {
                                    return;
                                }

                                $set('slug', Str::slug($state ?? ''));
                            }),
                        TextInput::make('slug')
                            ->label('Slug')
                            ->required()
                            ->maxLength(255)
                            ->alphaDash()
                            ->unique(
                                table: Company::class,
                                column: 'slug',
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule) => $rule,
                            ),
                        TextInput::make('document')
                            ->label('Documento')
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Telefone')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('timezone')
                            ->label('Fuso horário')
                            ->default('America/Sao_Paulo')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Empresa ativa')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }
}
