<?php

namespace App\Filament\App\Resources\Clients\Schemas;

use App\Models\Company;
use App\Support\CompanyTerminology;
use App\Support\Cpf;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
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
                            ->label(CompanyTerminology::client().' ativo')
                            ->default(true),
                        Toggle::make('whatsapp_marketing_opt_in')
                            ->label('Aceita campanhas no WhatsApp')
                            ->helperText('Use somente quando '.CompanyTerminology::client(capitalized: false).' autorizou receber mensagens promocionais.')
                            ->default(false),
                    ]),
                Section::make('Dados do paciente')
                    ->description('Informações específicas do prontuário odontológico.')
                    ->schema([
                        TextInput::make('dental_profile.record_number')
                            ->label('Número do prontuário')
                            ->disabled()
                            ->placeholder('Gerado automaticamente'),
                        TextInput::make('dental_profile.social_name')
                            ->label('Nome social')
                            ->maxLength(255),
                        Select::make('dental_profile.sex')
                            ->label('Sexo cadastral')
                            ->options([
                                'female' => 'Feminino',
                                'male' => 'Masculino',
                                'other' => 'Outro',
                                'not_informed' => 'Não informado',
                            ]),
                        TextInput::make('dental_profile.postal_code')->label('CEP')->maxLength(9),
                        TextInput::make('dental_profile.street')->label('Logradouro')->maxLength(255),
                        TextInput::make('dental_profile.street_number')->label('Número')->maxLength(50),
                        TextInput::make('dental_profile.address_complement')->label('Complemento')->maxLength(255),
                        TextInput::make('dental_profile.district')->label('Bairro')->maxLength(255),
                        TextInput::make('dental_profile.city')->label('Cidade')->maxLength(255),
                        TextInput::make('dental_profile.state')->label('UF')->maxLength(2),
                    ])
                    ->columns(2)
                    ->visible(fn (): bool => self::isDentalTenant()),
                Section::make('Responsáveis')
                    ->schema([
                        Repeater::make('guardians')
                            ->label('Responsáveis legais ou financeiros')
                            ->schema([
                                TextInput::make('name')->label('Nome')->required()->maxLength(255),
                                TextInput::make('document')->label('CPF')->maxLength(14),
                                DatePicker::make('birth_date')->label('Nascimento')->native(false),
                                TextInput::make('relationship')->label('Parentesco')->maxLength(100),
                                TextInput::make('phone')->label('Telefone')->tel()->maxLength(255),
                                TextInput::make('email')->label('E-mail')->email()->maxLength(255),
                                Toggle::make('is_legal_guardian')->label('Responsável legal')->default(true),
                                Toggle::make('is_financial_guardian')->label('Responsável financeiro')->default(false),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (): bool => self::isDentalTenant()),
                Section::make('Convênios')
                    ->schema([
                        Repeater::make('insurances')
                            ->label('Convênios do paciente')
                            ->schema([
                                TextInput::make('provider')->label('Operadora')->required()->maxLength(255),
                                TextInput::make('plan')->label('Plano')->maxLength(255),
                                TextInput::make('card_number')->label('Carteirinha')->maxLength(255),
                                DatePicker::make('expires_at')->label('Validade')->native(false),
                                TextInput::make('holder_name')->label('Titular')->maxLength(255),
                                Textarea::make('notes')->label('Observações')->rows(2)->columnSpanFull(),
                                Toggle::make('is_active')->label('Ativo')->default(true),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ])
                    ->visible(fn (): bool => self::isDentalTenant()),
            ]);
    }

    protected static function isDentalTenant(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company && $company->isDentalClinic();
    }
}
