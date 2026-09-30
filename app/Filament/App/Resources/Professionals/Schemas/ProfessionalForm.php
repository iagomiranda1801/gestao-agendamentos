<?php

namespace App\Filament\App\Resources\Professionals\Schemas;

use App\Enums\ClinicalSpecialty;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyPermissionService;
use App\Support\CompanyTerminology;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
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
                            ->default(fn (): ?string => self::initialPersonalUser()?->name)
                            ->maxLength(255),
                        TextInput::make('specialty')
                            ->label('Especialidade (livre)')
                            ->maxLength(255),
                        Select::make('clinical_specialty')
                            ->label('Especialidade clínica')
                            ->options(ClinicalSpecialty::options())
                            ->native(false)
                            ->nullable()
                            ->visible(fn (): bool => ($company = Filament::getTenant()) instanceof Company && $company->usesClinicalChart())
                            ->default(function (): ?string {
                                $company = Filament::getTenant();

                                if (! $company instanceof Company) {
                                    return null;
                                }

                                if ($company->isDentalClinic()) {
                                    return ClinicalSpecialty::Dentistry->value;
                                }

                                if ($company->isPsychiatrist()) {
                                    return ClinicalSpecialty::Psychiatry->value;
                                }

                                return null;
                            })
                            ->helperText('Define o questionário de anamnese deste profissional.'),
                        TextInput::make('phone')
                            ->label('Telefone')
                            ->tel()
                            ->default(fn (): ?string => self::initialPersonalUser() ? Filament::getTenant()?->phone : null)
                            ->maxLength(255),
                        TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->required(fn (Get $get): bool => (bool) $get('create_access'))
                            ->default(fn (): ?string => self::initialPersonalUser()?->email)
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
                            ->default(fn (): ?int => self::initialPersonalUser()?->getKey())
                            ->searchable()
                            ->preload()
                            ->nullable()
                            ->native(false)
                            ->options(fn (): array => self::availableUsers())
                            ->helperText('Somente usuários ativos com vínculo ativo nesta empresa.')
                            ->visible(fn (Get $get): bool => ! (bool) $get('create_access')),
                        Toggle::make('create_access')
                            ->label('Criar acesso ao painel')
                            ->helperText('O e-mail informado nos dados profissionais será usado para entrar no painel.')
                            ->default(false)
                            ->live()
                            ->visible(fn (string $operation): bool => $operation === 'create' && self::canManageAccess()),
                        Select::make('access_role')
                            ->label('Papel no painel')
                            ->options(fn (): array => CompanyRole::options(Filament::getTenant() instanceof Company ? Filament::getTenant() : null))
                            ->default(CompanyRole::Employee->value)
                            ->required(fn (Get $get): bool => (bool) $get('create_access'))
                            ->visible(fn (Get $get): bool => (bool) $get('create_access')),
                        TextInput::make('access_password')
                            ->label('Senha inicial')
                            ->password()
                            ->required(fn (Get $get): bool => (bool) $get('create_access'))
                            ->visible(fn (Get $get): bool => (bool) $get('create_access')),
                        TextInput::make('access_password_confirmation')
                            ->label('Confirme a senha')
                            ->password()
                            ->required(fn (Get $get): bool => (bool) $get('create_access'))
                            ->visible(fn (Get $get): bool => (bool) $get('create_access')),
                    ]),
                Section::make('Configurações')
                    ->schema([
                        Toggle::make('is_bookable')
                            ->label('Disponível para agendamento')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label(fn (): string => CompanyTerminology::professional().' ativo')
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

    protected static function canManageAccess(): bool
    {
        $company = Filament::getTenant();
        $user = auth()->user();

        return $company instanceof Company && $user instanceof User
            && app(CompanyPermissionService::class)->allows($user, $company, CompanyPermission::ManagePermissions);
    }

    protected static function initialPersonalUser(): ?User
    {
        $company = Filament::getTenant();
        $user = auth()->user();

        if (! $company instanceof Company || ! $company->isPersonalTrainer() || ! $user instanceof User
            || $company->professionals()->exists()
            || ! $user->hasActiveCompanyMembershipWith($company)) {
            return null;
        }

        return $user;
    }
}
