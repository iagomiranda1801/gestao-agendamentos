<?php

namespace App\Filament\Admin\Resources\Companies\Schemas;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Services\Company\CompanySubscriptionService;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Actions;
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
                        Actions::make([
                            Action::make('presetEssential')
                                ->label('Essencial')
                                ->color('gray')
                                ->action(fn (Set $set) => self::applyPreset($set, 'essential')),
                            Action::make('presetProfessional')
                                ->label('Profissional')
                                ->color('gray')
                                ->action(fn (Set $set) => self::applyPreset($set, 'professional')),
                            Action::make('presetComplete')
                                ->label('Completo')
                                ->color('gray')
                                ->action(fn (Set $set) => self::applyPreset($set, 'complete')),
                        ]),
                        CheckboxList::make('enabled_modules')
                            ->label('Recursos ativados')
                            ->options(CompanyModule::options())
                            ->descriptions(collect(CompanyModule::cases())
                                ->mapWithKeys(fn (CompanyModule $module) => [$module->value => $module->description()])
                                ->all())
                            ->columns(1)
                            ->required()
                            ->live()
                            ->default([CompanyModule::Scheduling->value])
                            ->helperText('O perfil ou os atalhos preenchem uma sugestão. O valor é a soma dos módulos no ciclo.')
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
                            ->visible(fn (Get $get): bool => $get('subscription_status') === SubscriptionStatus::Trial->value),
                        Select::make('billing_interval')
                            ->label('Ciclo')
                            ->options(BillingInterval::options())
                            ->native(false)
                            ->live()
                            ->helperText('Obrigatório para registrar pagamento.'),
                        Placeholder::make('catalog_total')
                            ->label('Total do ciclo (catálogo)')
                            ->content(function (Get $get): string {
                                $interval = BillingInterval::tryFrom((string) ($get('billing_interval') ?? ''));

                                if ($interval === null) {
                                    return 'Escolha o ciclo para ver o valor.';
                                }

                                $cents = app(CompanySubscriptionService::class)->quoteCents(
                                    $get('enabled_modules') ?? [],
                                    $interval,
                                );

                                return app(CompanySubscriptionService::class)->formatReais($cents);
                            }),
                        DateTimePicker::make('current_period_end')
                            ->label('Vigente até')
                            ->native(false)
                            ->visible(fn (Get $get): bool => $get('subscription_status') === SubscriptionStatus::Active->value),
                        Placeholder::make('quoted_snapshot')
                            ->label('Valor vigente (snapshot)')
                            ->content(function (?Company $record): string {
                                if ($record === null) {
                                    return '—';
                                }

                                return app(CompanySubscriptionService::class)->formatReais($record->quoted_price_cents);
                            }),
                    ])
                    ->columns(2),
            ]);
    }

    protected static function applyPreset(Set $set, string $preset): void
    {
        $set(
            'enabled_modules',
            collect(CompanyModule::billingPreset($preset))
                ->map(fn (CompanyModule $module) => $module->value)
                ->all(),
        );
    }
}
