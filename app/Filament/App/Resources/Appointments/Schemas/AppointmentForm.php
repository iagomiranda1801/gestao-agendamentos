<?php

namespace App\Filament\App\Resources\Appointments\Schemas;

use App\Enums\AppointmentOrigin;
use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Filament\App\Support\QuickCreateFields;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\PatientClinicalAlert;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Company\CompanyPermissionService;
use App\Support\CompanyDateTime;
use App\Support\CompanyTerminology;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class AppointmentForm
{
    public static function configure(Schema $schema, bool $readOnly = false): Schema
    {
        return $schema
            ->components([
                Section::make('Agendamento')
                    ->schema([
                        QuickCreateFields::applyClientCreate(
                            Select::make('client_id')
                                ->label(CompanyTerminology::client())
                                ->relationship(
                                    'client',
                                    'name',
                                    fn (Builder $query): Builder => $query
                                        ->where('company_id', Filament::getTenant()?->getKey())
                                        ->active(),
                                )
                                ->searchable(['name', 'phone', 'phone_normalized', 'email'])
                                ->preload()
                                ->required()
                                ->native(false)
                                ->live()
                                ->disabled($readOnly),
                        ),
                        Select::make('service_selection_mode')
                            ->label('Procedimento')
                            ->options([
                                'defined' => 'Selecionar serviço',
                                'to_be_defined' => 'Definir no atendimento',
                            ])
                            ->default('defined')
                            ->required()
                            ->native(false)
                            ->live()
                            ->disabled($readOnly)
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if ($state === 'to_be_defined') {
                                    $set('service_id', null);
                                }
                            }),
                        QuickCreateFields::applyServiceCreate(
                            Select::make('service_id')
                                ->label('Serviço')
                                ->options(fn (): array => self::serviceOptions())
                                ->searchable()
                                ->nullable()
                                ->helperText('Deixe em branco para definir o procedimento no atendimento.')
                                ->native(false)
                                ->live()
                                ->visible(fn (Get $get): bool => $get('service_selection_mode') !== 'to_be_defined')
                                ->disabled($readOnly)
                                ->afterStateUpdated(function (?int $state, Set $set, Get $get): void {
                                    if ($state !== null) {
                                        $set('service_selection_mode', 'defined');
                                    }
                                    self::syncPreviewFields($set, $get, $state, $get('professional_id'));
                                    $professionalId = $get('professional_id');
                                    if ($professionalId && ! self::professionalEligible($state, $professionalId)) {
                                        $set('professional_id', null);
                                    }
                                }),
                        ),
                        Select::make('professional_id')
                            ->label(CompanyTerminology::professional())
                            ->options(fn (Get $get): array => self::isOpenServiceSelection($get)
                                ? self::openAppointmentProfessionalOptions()
                                : self::professionalOptions($get('service_id')))
                            ->searchable()
                            ->required()
                            ->native(false)
                            ->live()
                            ->disabled($readOnly)
                            ->afterStateUpdated(fn (?int $state, Set $set, Get $get) => self::syncPreviewFields(
                                $set,
                                $get,
                                $get('service_id'),
                                $state,
                            )),
                        TextInput::make('duration_minutes_snapshot')
                            ->label('Duração prevista')
                            ->numeric()
                            ->minValue(15)
                            ->maxValue(480)
                            ->suffix('min')
                            ->required(fn (Get $get): bool => self::isOpenServiceSelection($get))
                            ->visible(fn (Get $get): bool => self::isOpenServiceSelection($get))
                            ->live()
                            ->afterStateUpdated(fn (Set $set, Get $get) => self::syncEndPreview($set, $get))
                            ->disabled($readOnly),
                        DatePicker::make('appointment_date')
                            ->label('Data')
                            ->required()
                            ->native(false)
                            ->disabled($readOnly)
                            ->live()
                            ->afterStateUpdated(fn (Set $set, Get $get) => self::syncEndPreview($set, $get)),
                        TimePicker::make('appointment_time')
                            ->label('Hora inicial')
                            ->seconds(false)
                            ->required()
                            ->disabled($readOnly)
                            ->live()
                            ->afterStateUpdated(fn (Set $set, Get $get) => self::syncEndPreview($set, $get)),
                        TextInput::make('duration_preview')
                            ->label('Duração')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (Get $get): bool => ! self::isOpenServiceSelection($get))
                            ->formatStateUsing(fn ($state, Get $get): string => self::durationLabel($get('service_id'), $get('professional_id'))),
                        TextInput::make('end_time_preview')
                            ->label('Hora final')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($state, Get $get): string => self::endTimeLabel($get)),
                        TextInput::make('price_preview')
                            ->label('Preço previsto')
                            ->prefix('R$')
                            ->disabled()
                            ->dehydrated(false)
                            ->formatStateUsing(fn ($state, Get $get): string => self::isOpenServiceSelection($get)
                                ? 'A combinar'
                                : self::priceLabel($get('service_id'), $get('professional_id'))),
                        TextInput::make('status_label')
                            ->label('Status')
                            ->disabled()
                            ->dehydrated(false)
                            ->visible(fn (?string $operation): bool => $operation !== 'create'),
                    ])
                    ->columns(2),
                Section::make('Alertas clínicos')
                    ->schema([
                        Placeholder::make('clinical_alerts_summary')
                            ->label('Cuidados importantes')
                            ->content(function (Get $get): HtmlString|string {
                                $clientId = $get('client_id');
                                if (! $clientId) {
                                    return 'Selecione o paciente para consultar os alertas.';
                                }
                                $alerts = PatientClinicalAlert::query()
                                    ->where('company_id', Filament::getTenant()?->getKey())
                                    ->where('client_id', $clientId)
                                    ->where('is_active', true)
                                    ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'attention' THEN 2 ELSE 3 END")
                                    ->get();
                                if ($alerts->isEmpty()) {
                                    return 'Nenhum alerta clínico ativo.';
                                }

                                return new HtmlString($alerts->map(fn (PatientClinicalAlert $alert): string => '<div><strong>'.e($alert->title).'</strong>'.(filled($alert->description) ? ' — '.e($alert->description) : '').'</div>')->implode(''));
                            }),
                    ])
                    ->visible(fn (): bool => self::canViewClinicalAlerts()),
                Section::make('Agendamento online')
                    ->schema([
                        TextInput::make('public_confirmation_code')
                            ->label('Código de confirmação')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('client_name_snapshot')
                            ->label('Nome informado na reserva')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('client_phone_snapshot')
                            ->label('Telefone informado na reserva')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('client_email_snapshot')
                            ->label('E-mail informado na reserva')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('public_booked_at_label')
                            ->label('Reservado online em')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('privacy_accepted_label')
                            ->label('Aceite de privacidade')
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('terms_accepted_label')
                            ->label('Aceite dos termos')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2)
                    ->visible(fn (?Appointment $record): bool => $record !== null && $record->origin === AppointmentOrigin::Online),
                Section::make('Informações')
                    ->schema([
                        Textarea::make('appointment_reason')
                            ->label('Motivo da consulta')
                            ->rows(2)
                            ->columnSpanFull()
                            ->visible(fn (Get $get): bool => self::isOpenServiceSelection($get))
                            ->disabled($readOnly),
                        Textarea::make('notes')
                            ->label('Observações')
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled($readOnly),
                        Textarea::make('internal_notes')
                            ->label('Anotações internas')
                            ->rows(3)
                            ->columnSpanFull()
                            ->disabled($readOnly)
                            ->visible(fn (): bool => self::canViewInternalNotes()),
                    ]),
            ]);
    }

    protected static function canViewInternalNotes(): bool
    {
        $user = auth()->user();
        /** @var Company|null $company */
        $company = Filament::getTenant();

        if (! $user || ! $company) {
            return false;
        }

        return $user->is_super_admin || $user->hasActiveRoleInCompany(
            $company,
            CompanyRole::CompanyAdmin,
            CompanyRole::Manager,
        );
    }

    protected static function canViewClinicalAlerts(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company
            && $company->isDentalClinic()
            && auth()->user() !== null
            && app(CompanyPermissionService::class)->allows(auth()->user(), $company, CompanyPermission::ViewClinicalAlerts);
    }

    /**
     * @return array<int, string>
     */
    protected static function serviceOptions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Service::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->bookable()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function professionalOptions(?int $serviceId): array
    {
        if (! $serviceId) {
            return [];
        }

        /** @var Company $company */
        $company = Filament::getTenant();

        return Professional::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->bookable()
            ->whereHas('services', fn (Builder $query): Builder => $query
                ->where('services.id', $serviceId)
                ->where('professional_service.is_active', true))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected static function openAppointmentProfessionalOptions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Professional::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->bookable()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    protected static function isOpenServiceSelection(Get $get): bool
    {
        return $get('service_selection_mode') === 'to_be_defined'
            || blank($get('service_id'));
    }

    protected static function professionalEligible(?int $serviceId, ?int $professionalId): bool
    {
        if (! $serviceId || ! $professionalId) {
            return false;
        }

        return array_key_exists($professionalId, self::professionalOptions($serviceId));
    }

    protected static function syncPreviewFields(Set $set, Get $get, ?int $serviceId, ?int $professionalId): void
    {
        $set('duration_preview', self::durationLabel($serviceId, $professionalId));
        $set('price_preview', self::priceLabel($serviceId, $professionalId));
        self::syncEndPreview($set, $get);
    }

    protected static function syncEndPreview(Set $set, Get $get): void
    {
        $set('end_time_preview', self::endTimeLabel($get));
    }

    protected static function durationLabel(?int $serviceId, ?int $professionalId): string
    {
        $minutes = self::resolveDuration($serviceId, $professionalId);

        return $minutes ? "{$minutes} min" : '—';
    }

    protected static function priceLabel(?int $serviceId, ?int $professionalId): string
    {
        $price = self::resolvePrice($serviceId, $professionalId);

        return $price !== null ? number_format((float) $price, 2, ',', '.') : '—';
    }

    protected static function endTimeLabel(Get $get): string
    {
        $date = $get('appointment_date');
        $time = $get('appointment_time');
        $duration = self::isOpenServiceSelection($get)
            ? (int) $get('duration_minutes_snapshot')
            : self::resolveDuration($get('service_id'), $get('professional_id'));

        if (! $date || ! $time || ! $duration) {
            return '—';
        }

        /** @var Company $company */
        $company = Filament::getTenant();

        $start = CompanyDateTime::parseLocal($company, $date, $time);

        return $start->addMinutes($duration)->format('H:i');
    }

    protected static function resolveDuration(?int $serviceId, ?int $professionalId): ?int
    {
        if (! $serviceId) {
            return null;
        }

        $service = Service::query()->find($serviceId);

        if (! $service) {
            return null;
        }

        if ($professionalId) {
            $link = Professional::query()
                ->find($professionalId)
                ?->services()
                ->where('services.id', $serviceId)
                ->first()
                ?->pivot;

            if (filled($link?->custom_duration_minutes)) {
                return (int) $link->custom_duration_minutes;
            }
        }

        return (int) $service->duration_minutes;
    }

    protected static function resolvePrice(?int $serviceId, ?int $professionalId): ?string
    {
        if (! $serviceId) {
            return null;
        }

        $service = Service::query()->find($serviceId);

        if (! $service) {
            return null;
        }

        if ($professionalId) {
            $link = Professional::query()
                ->find($professionalId)
                ?->services()
                ->where('services.id', $serviceId)
                ->first()
                ?->pivot;

            if (filled($link?->custom_price)) {
                return (string) $link->custom_price;
            }
        }

        return (string) $service->price;
    }
}
