<?php

namespace App\Filament\App\Resources\Professionals\Schemas;

use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProfessionalForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Dados profissionais')
                    ->schema([
                        TextInput::make('name')
                            ->label('Nome')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('specialty')
                            ->label('Especialidade')
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Telefone')
                            ->tel()
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('document')
                            ->label('Documento')
                            ->maxLength(255),
                        ColorPicker::make('color')
                            ->label('Cor da agenda'),
                        TextInput::make('sort_order')
                            ->label('Ordem de exibição')
                            ->numeric()
                            ->default(0)
                            ->minValue(0),
                    ])
                    ->columns(2),
                Section::make('Acesso ao sistema')
                    ->schema([
                        Select::make('user_id')
                            ->label('Usuário vinculado')
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false)
                            ->options(fn (): array => self::availableUsers())
                            ->helperText('Somente usuários ativos com vínculo ativo nesta empresa.'),
                    ]),
                Section::make('Configurações')
                    ->schema([
                        Toggle::make('is_bookable')
                            ->label('Disponível para agendamento')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Profissional ativo')
                            ->default(true),
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(4)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * @return array<int, string>
     */
    protected static function availableUsers(): array
    {
        /** @var Company|null $company */
        $company = Filament::getTenant();

        if (! $company) {
            return [];
        }

        return User::query()
            ->where('is_active', true)
            ->where('is_super_admin', false)
            ->whereHas('companies', function ($query) use ($company): void {
                $query
                    ->where('companies.id', $company->getKey())
                    ->where('companies.is_active', true)
                    ->where('company_user.is_active', true);
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
