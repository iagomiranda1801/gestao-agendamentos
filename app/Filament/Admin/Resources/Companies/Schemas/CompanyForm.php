<?php

namespace App\Filament\Admin\Resources\Companies\Schemas;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                        Select::make('business_profile')
                            ->label('Perfil do negócio')
                            ->options(CompanyProfile::options())
                            ->default(CompanyProfile::Custom->value)
                            ->helperText(fn (Get $get): string => CompanyProfile::tryFrom((string) ($get('business_profile') ?? 'custom'))?->description() ?? '')
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                $profile = CompanyProfile::tryFrom((string) $state);

                                if ($profile !== null) {
                                    $set('enabled_modules', collect($profile->defaultModules())->map(fn (CompanyModule $module) => $module->value)->all());
                                }
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
                Section::make('Módulos')
                    ->schema([
                        CheckboxList::make('enabled_modules')
                            ->label('Recursos ativados')
                            ->options(CompanyModule::options())
                            ->descriptions(collect(CompanyModule::cases())
                                ->mapWithKeys(fn (CompanyModule $module) => [$module->value => $module->description()])
                                ->all())
                            ->columns(1)
                            ->required()
                            ->default([CompanyModule::Scheduling->value])
                            ->helperText('O perfil preenche uma sugestão. Você pode ajustar os recursos antes de salvar.')
                            ->bulkToggleable(),
                    ]),
                Section::make('Assinatura')
                    ->schema([
                        Select::make('subscription_status')
                            ->label('Status da assinatura')
                            ->options(SubscriptionStatus::options())
                            ->default(SubscriptionStatus::Trial->value)
                            ->required()
                            ->native(false)
                            ->live(),
                        DateTimePicker::make('trial_ends_at')
                            ->label('Trial até')
                            ->native(false)
                            ->default(fn (): ?\Illuminate\Support\Carbon => now()->addDays(7))
                            ->visible(fn (callable $get): bool => $get('subscription_status') === SubscriptionStatus::Trial->value),
                    ])
                    ->columns(2),
            ]);
    }
}
