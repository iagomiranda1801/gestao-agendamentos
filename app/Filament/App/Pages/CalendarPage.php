<?php

namespace App\Filament\App\Pages;

use App\Enums\AppointmentStatus;
use App\Enums\CompanyRole;
use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Policies\AppointmentPolicy;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Support\CompanyDateTime;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class CalendarPage extends Page
{
    protected static ?string $slug = 'agenda';

    protected static ?string $navigationLabel = 'Agenda';

    protected static ?string $title = 'Agenda';

    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static string $layout = 'filament-panels::components.layout.index';

    public ?int $filterProfessionalId = null;

    public ?int $filterServiceId = null;

    public ?string $filterStatus = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (new AppointmentPolicy)->viewAny($user);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function canManageCalendar(): bool
    {
        $user = auth()->user();
        /** @var Company $company */
        $company = Filament::getTenant();

        return $user !== null && (
            $user->is_super_admin
            || $user->hasActiveRoleInCompany(
                $company,
                CompanyRole::CompanyAdmin,
                CompanyRole::Manager,
            )
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchEvents(string $start, string $end): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();
        $user = auth()->user();

        abort_unless($user !== null, 403);

        $events = app(AppointmentService::class)->fetchCalendarEvents(
            $company,
            $user,
            CarbonImmutable::parse($start),
            CarbonImmutable::parse($end),
            [
                'professional_id' => $this->filterProfessionalId,
                'service_id' => $this->filterServiceId,
                'status' => $this->filterStatus,
            ],
        );

        return array_map(function (array $event) use ($company): array {
            $event['extendedProps']['viewUrl'] = AppointmentResource::getUrl('view', [
                'tenant' => $company,
                'record' => $event['id'],
            ]);

            return $event;
        }, $events);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromSelection(array $data): void
    {
        abort_unless($this->canManageCalendar(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();

        app(AppointmentService::class)->createFromSelection($company, auth()->user(), $data);

        Notification::make()
            ->success()
            ->title('Agendamento criado')
            ->send();

        $this->dispatch('scheduling-calendar:refresh');
    }

    /**
     * @return array{success: bool, message?: string}
     */
    public function rescheduleFromDrag(int $appointmentId, string $start, ?int $professionalId = null): array
    {
        abort_unless($this->canManageCalendar(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();

        $appointment = Appointment::query()
            ->where('company_id', $company->getKey())
            ->findOrFail($appointmentId);

        $this->authorize('reschedule', $appointment);

        $localStart = CarbonImmutable::parse($start)->setTimezone(CompanyDateTime::timezone($company));

        return app(AppointmentService::class)->rescheduleFromDrag(
            $company,
            auth()->user(),
            $appointment,
            $localStart,
            $professionalId,
        );
    }

    public function updatedFilterProfessionalId(mixed $value): void
    {
        $this->filterProfessionalId = blank($value) ? null : (int) $value;
        $this->dispatch('scheduling-calendar:refresh');
    }

    public function updatedFilterServiceId(mixed $value): void
    {
        $this->filterServiceId = blank($value) ? null : (int) $value;
        $this->dispatch('scheduling-calendar:refresh');
    }

    public function updatedFilterStatus(mixed $value): void
    {
        $this->filterStatus = blank($value) ? null : (string) $value;
        $this->dispatch('scheduling-calendar:refresh');
    }

    /**
     * @return array<string, mixed>
     */
    public function getCalendarConfig(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();
        $settings = app(CompanySchedulingSettingService::class)->getOrCreate($company);

        return [
            'locale' => 'pt-br',
            'initialView' => $settings->default_calendar_view,
            'slotMinTime' => substr((string) $settings->calendar_start_time, 0, 8),
            'slotMaxTime' => substr((string) $settings->calendar_end_time, 0, 8),
            'firstDay' => $settings->week_starts_on,
            'canManage' => $this->canManageCalendar(),
            'slotDuration' => sprintf('00:%02d:00', $settings->slot_interval_minutes),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([
                    Section::make()
                        ->schema([
                            Select::make('filterProfessionalId')
                                ->label('Profissional')
                                ->placeholder('Todos')
                                ->options(fn (): array => $this->getProfessionalFilterOptions())
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(fn () => $this->dispatch('scheduling-calendar:refresh'))
                                ->native(false),
                            Select::make('filterServiceId')
                                ->label('Serviço')
                                ->placeholder('Todos')
                                ->options(fn (): array => $this->getServiceFilterOptions())
                                ->searchable()
                                ->live()
                                ->afterStateUpdated(fn () => $this->dispatch('scheduling-calendar:refresh'))
                                ->native(false),
                            Select::make('filterStatus')
                                ->label('Status')
                                ->placeholder('Todos')
                                ->options(AppointmentStatus::options())
                                ->live()
                                ->afterStateUpdated(fn () => $this->dispatch('scheduling-calendar:refresh'))
                                ->native(false),
                        ])
                        ->columns(['default' => 1, 'sm' => 2, 'xl' => 3])
                        ->compact()
                        ->extraAttributes(['class' => 'scheduling-filters-section']),
                ]),
                ViewComponent::make('filament.app.pages.scheduling-calendar')
                    ->extraAttributes(['class' => 'scheduling-calendar-host']),
            ]);
    }

    /**
     * @return array<int|string, string>
     */
    protected function getProfessionalFilterOptions(): array
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return Professional::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int|string, string>
     */
    protected function getServiceFilterOptions(): array
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

    protected function getHeaderActions(): array
    {
        if (! $this->canManageCalendar()) {
            return [];
        }

        return [
            Action::make('createFromSelection')
                ->label('Novo agendamento')
                ->icon('heroicon-o-plus')
                ->schema([
                    Select::make('client_id')
                        ->label('Cliente')
                        ->options(fn (): array => Client::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->active()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required()
                        ->native(false),
                    Select::make('service_id')
                        ->label('Serviço')
                        ->options(fn (): array => Service::query()
                            ->where('company_id', Filament::getTenant()?->getKey())
                            ->active()
                            ->bookable()
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->required()
                        ->live()
                        ->native(false),
                    Select::make('professional_id')
                        ->label('Profissional')
                        ->options(function (callable $get): array {
                            $serviceId = $get('service_id');

                            if (! $serviceId) {
                                return [];
                            }

                            return Professional::query()
                                ->where('company_id', Filament::getTenant()?->getKey())
                                ->active()
                                ->bookable()
                                ->whereHas('services', fn ($query) => $query
                                    ->where('services.id', $serviceId)
                                    ->where('professional_service.is_active', true))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all();
                        })
                        ->required()
                        ->native(false),
                    DatePicker::make('appointment_date')
                        ->label('Data')
                        ->required()
                        ->native(false),
                    TimePicker::make('appointment_time')
                        ->label('Hora inicial')
                        ->seconds(false)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->createFromSelection($data);
                }),
        ];
    }

    public function openCreateFromSelection(string $startIso, ?string $endIso = null): void
    {
        abort_unless($this->canManageCalendar(), 403);

        /** @var Company $company */
        $company = Filament::getTenant();
        $localStart = CarbonImmutable::parse($startIso)->setTimezone(CompanyDateTime::timezone($company));

        $this->mountAction('createFromSelection', [
            'appointment_date' => $localStart->toDateString(),
            'appointment_time' => $localStart->format('H:i'),
        ]);
    }
}
