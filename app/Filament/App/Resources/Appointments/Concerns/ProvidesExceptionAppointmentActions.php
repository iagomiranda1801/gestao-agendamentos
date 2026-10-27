<?php

namespace App\Filament\App\Resources\Appointments\Concerns;

use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Filament\App\Support\AppointmentSchedulingForm;
use App\Filament\App\Support\QuickCreateFields;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Policies\AppointmentPolicy;
use App\Services\Scheduling\AppointmentService;
use App\Support\CompanyDateTime;
use App\Support\CompanyTerminology;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

trait ProvidesExceptionAppointmentActions
{
    /**
     * @return list<Action>
     */
    protected function exceptionAppointmentHeaderActions(): array
    {
        if (! $this->canBookException()) {
            return [];
        }

        return [
            $this->quickAttendanceAction(),
            $this->fitInAppointmentAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeQuickAttendance(array $data): void
    {
        abort_unless($this->canBookException(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();
        $client = Client::query()->findOrFail($data['client_id']);
        $professional = Professional::query()->findOrFail($data['professional_id']);
        $service = filled($data['service_id'] ?? null) ? Service::query()->findOrFail($data['service_id']) : null;

        try {
            $appointment = app(AppointmentService::class)->createQuickAttendance(
                $company,
                auth()->user(),
                $client,
                $professional,
                $service,
                $data,
            );
        } catch (ValidationException $exception) {
            AppointmentSchedulingForm::notifyAndRethrow($exception, $this->mountedActionStatePath());
        }

        Notification::make()
            ->success()
            ->title('Atendimento iniciado')
            ->send();

        $this->dispatch('scheduling-calendar:refresh');
        $this->redirect($this->appointmentViewUrl($appointment));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function storeFitIn(array $data): void
    {
        abort_unless($this->canBookException(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();
        $client = Client::query()->findOrFail($data['client_id']);
        $professional = Professional::query()->findOrFail($data['professional_id']);
        $service = filled($data['service_id'] ?? null) ? Service::query()->findOrFail($data['service_id']) : null;
        $localStart = CompanyDateTime::parseLocal(
            $company,
            (string) $data['appointment_date'],
            (string) $data['appointment_time'],
        );

        try {
            app(AppointmentService::class)->createFitInAppointment(
                $company,
                auth()->user(),
                $client,
                $professional,
                $service,
                $localStart,
                $data,
            );
        } catch (ValidationException $exception) {
            AppointmentSchedulingForm::notifyAndRethrow($exception, $this->mountedActionStatePath());
        }

        Notification::make()
            ->success()
            ->title('Encaixe criado')
            ->send();

        $this->dispatch('scheduling-calendar:refresh');
    }

    protected function quickAttendanceAction(): Action
    {
        return Action::make('quickAttendance')
            ->label('Atender agora')
            ->icon('heroicon-o-play')
            ->color('info')
            ->modalHeading('Atender agora')
            ->modalSubmitActionLabel('Iniciar atendimento')
            ->schema([
                ...$this->exceptionClientAndServiceFields(),
                TextInput::make('duration_minutes_snapshot')
                    ->label('Duração prevista')
                    ->numeric()
                    ->minValue(15)
                    ->maxValue(480)
                    ->suffix('min')
                    ->required(fn (callable $get): bool => blank($get('service_id')))
                    ->visible(fn (callable $get): bool => blank($get('service_id')))
                    ->live(),
                Placeholder::make('quick_window')
                    ->label('Início e fim previsto')
                    ->content(fn (callable $get): string => $this->exceptionWindowLabel($get, true)),
                $this->exceptionOverlapPlaceholder(true),
                Textarea::make('fit_in_reason')
                    ->label('Motivo do encaixe')
                    ->helperText('Obrigatório apenas quando já existe outro compromisso neste horário.')
                    ->rows(2)
                    ->maxLength(500)
                    ->required(fn (callable $get): bool => $this->exceptionSlotOverlaps($get, true))
                    ->columnSpanFull(),
                Toggle::make('send_whatsapp_confirmation')
                    ->label('Enviar confirmação ao cliente por WhatsApp')
                    ->helperText('Começa desligada porque o cliente já está presente.')
                    ->default(false),
            ])
            ->action(function (array $data): void {
                $this->storeQuickAttendance($data);
            });
    }

    protected function fitInAppointmentAction(): Action
    {
        return Action::make('fitInAppointment')
            ->label('Criar encaixe')
            ->icon('heroicon-o-queue-list')
            ->color('warning')
            ->modalHeading('Criar encaixe')
            ->modalSubmitActionLabel('Confirmar encaixe')
            ->schema([
                ...$this->exceptionClientAndServiceFields(),
                TextInput::make('duration_minutes_snapshot')
                    ->label('Duração prevista')
                    ->numeric()
                    ->minValue(15)
                    ->maxValue(480)
                    ->suffix('min')
                    ->required(fn (callable $get): bool => blank($get('service_id')))
                    ->visible(fn (callable $get): bool => blank($get('service_id')))
                    ->live(),
                DatePicker::make('appointment_date')
                    ->label('Data')
                    ->required()
                    ->native(false)
                    ->live(),
                AppointmentSchedulingForm::timePicker(
                    TimePicker::make('appointment_time')
                        ->label('Hora inicial')
                        ->required()
                        ->live(),
                ),
                Placeholder::make('fit_in_window')
                    ->label('Horário previsto')
                    ->content(fn (callable $get): string => $this->exceptionWindowLabel($get, false)),
                $this->exceptionOverlapPlaceholder(false),
                Textarea::make('fit_in_reason')
                    ->label('Motivo do encaixe')
                    ->required()
                    ->maxLength(500)
                    ->rows(2)
                    ->columnSpanFull(),
                Textarea::make('appointment_reason')
                    ->label('Motivo da consulta')
                    ->rows(2)
                    ->visible(fn (callable $get): bool => blank($get('service_id')))
                    ->columnSpanFull(),
                Toggle::make('send_whatsapp_confirmation')
                    ->label('Enviar confirmação ao cliente por WhatsApp')
                    ->helperText('A mensagem informa o horário confirmado, sem citar o conflito interno.')
                    ->default(true),
            ])
            ->action(function (array $data): void {
                $this->storeFitIn($data);
            });
    }

    /**
     * @return list<Select>
     */
    protected function exceptionClientAndServiceFields(): array
    {
        return [
            QuickCreateFields::applyClientCreate(
                Select::make('client_id')
                    ->label(CompanyTerminology::client())
                    ->options(fn (): array => Client::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->active()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->required()
                    ->native(false),
            ),
            QuickCreateFields::applyServiceCreate(
                Select::make('service_id')
                    ->label('Serviço')
                    ->options(fn (): array => Service::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->active()
                        ->bookable()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->nullable()
                    ->helperText('Opcional. Deixe em branco para definir o procedimento no atendimento.')
                    ->live()
                    ->native(false),
            ),
            Select::make('professional_id')
                ->label('Profissional')
                ->default(fn (): ?int => $this->exceptionProfessionalDefault())
                ->options(function (callable $get): array {
                    $serviceId = $get('service_id');
                    $query = Professional::query()
                        ->where('company_id', Filament::getTenant()?->getKey())
                        ->active()
                        ->bookable()
                        ->orderBy('name');

                    if ($serviceId) {
                        $query->whereHas('services', fn ($builder) => $builder
                            ->where('services.id', $serviceId)
                            ->where('professional_service.is_active', true));
                    }

                    return $query->pluck('name', 'id')->all();
                })
                ->required()
                ->searchable()
                ->live()
                ->native(false),
        ];
    }

    protected function exceptionOverlapPlaceholder(bool $useNow): Placeholder
    {
        return Placeholder::make($useNow ? 'quick_overlaps' : 'fit_in_overlaps')
            ->label('Compromissos afetados')
            ->content(function (callable $get) use ($useNow): HtmlString {
                $company = Filament::getTenant();

                if (! $company instanceof Company) {
                    return new HtmlString('—');
                }

                return new HtmlString(app(AppointmentService::class)->overlapSummaryHtml(
                    $company,
                    filled($get('professional_id')) ? (int) $get('professional_id') : null,
                    filled($get('service_id')) ? (int) $get('service_id') : null,
                    filled($get('duration_minutes_snapshot')) ? (int) $get('duration_minutes_snapshot') : null,
                    $useNow ? null : ($get('appointment_date') ?: null),
                    $useNow ? null : ($get('appointment_time') ?: null),
                    $useNow,
                ));
            })
            ->columnSpanFull();
    }

    protected function exceptionWindowLabel(callable $get, bool $useNow): string
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return '—';
        }

        return app(AppointmentService::class)->formWindowLabel(
            $company,
            filled($get('professional_id')) ? (int) $get('professional_id') : null,
            filled($get('service_id')) ? (int) $get('service_id') : null,
            filled($get('duration_minutes_snapshot')) ? (int) $get('duration_minutes_snapshot') : null,
            $useNow ? null : ($get('appointment_date') ?: null),
            $useNow ? null : ($get('appointment_time') ?: null),
            $useNow,
        );
    }

    protected function exceptionSlotOverlaps(callable $get, bool $useNow): bool
    {
        $company = Filament::getTenant();

        if (! $company instanceof Company) {
            return false;
        }

        return app(AppointmentService::class)->formSlotHasOverlap(
            $company,
            filled($get('professional_id')) ? (int) $get('professional_id') : null,
            filled($get('service_id')) ? (int) $get('service_id') : null,
            filled($get('duration_minutes_snapshot')) ? (int) $get('duration_minutes_snapshot') : null,
            $useNow ? null : ($get('appointment_date') ?: null),
            $useNow ? null : ($get('appointment_time') ?: null),
            $useNow,
        );
    }

    protected function canBookException(): bool
    {
        $user = auth()->user();

        return $user !== null && (new AppointmentPolicy)->create($user);
    }

    protected function exceptionProfessionalDefault(): ?int
    {
        if (! property_exists($this, 'filterProfessionalId')) {
            return null;
        }

        return $this->filterProfessionalId;
    }

    protected function mountedActionStatePath(): ?string
    {
        if (! method_exists($this, 'getMountedActionSchema')) {
            return null;
        }

        return $this->getMountedActionSchema()?->getStatePath();
    }

    protected function appointmentViewUrl(Appointment $appointment): string
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return AppointmentResource::getUrl('view', [
            'tenant' => $company,
            'record' => $appointment,
        ]);
    }
}
