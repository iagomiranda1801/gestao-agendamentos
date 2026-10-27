<?php

namespace App\Services\Scheduling;

use App\Enums\AppointmentFlowKind;
use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Enums\CompanyRole;
use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\AppointmentHistory;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Models\ScheduleBlock;
use App\Models\Service;
use App\Models\User;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(
        protected AvailabilityService $availabilityService,
        protected AppointmentSnapshotResolver $snapshotResolver,
        protected AppointmentConflictService $conflictService,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createInternalAppointment(
        Company $company,
        User $user,
        Client $client,
        Professional $professional,
        ?Service $service,
        CarbonImmutable $localStart,
        array $data = [],
        bool $ignoreAppointmentConflicts = false,
        bool $ignoreSlotAlignment = false,
        AppointmentFlowKind $flowKind = AppointmentFlowKind::Normal,
    ): Appointment {
        return DB::transaction(function () use ($company, $user, $client, $professional, $service, $localStart, $data, $ignoreAppointmentConflicts, $ignoreSlotAlignment, $flowKind): Appointment {
            $professional = Professional::query()->whereKey($professional->getKey())->lockForUpdate()->firstOrFail();

            $isOpenService = ($data['service_selection_mode'] ?? 'defined') === 'to_be_defined' || $service === null;
            if ($isOpenService && $service !== null) {
                throw ValidationException::withMessages(['service_id' => 'Não selecione um serviço para um agendamento a definir.']);
            }
            $this->assertRelatedModels($company, $client, $professional, $service);

            $snapshots = $isOpenService
                ? $this->resolveOpenServiceSnapshots($data)
                : $this->resolveSnapshots($company, $professional, $service);
            $startUtc = CompanyDateTime::localToUtc($company, $localStart);
            $endUtc = $startUtc->addMinutes($snapshots['duration_minutes_snapshot']);
            $fitInReason = $this->normalizedFitInReason($data['fit_in_reason'] ?? null, $flowKind);

            $conflicts = $this->conflictService->conflictingAppointments(
                $company,
                $professional,
                $startUtc,
                $endUtc,
                $snapshots['buffer_before_minutes_snapshot'],
                $snapshots['buffer_after_minutes_snapshot'],
            );

            if ($flowKind === AppointmentFlowKind::QuickAttendance && $conflicts->isNotEmpty() && $fitInReason === null) {
                throw ValidationException::withMessages([
                    'fit_in_reason' => 'Há outro compromisso neste horário. Informe o motivo do encaixe para atender agora.',
                ]);
            }

            $ignoreConflicts = $ignoreAppointmentConflicts
                || ($flowKind === AppointmentFlowKind::QuickAttendance && $conflicts->isNotEmpty());

            $this->availabilityService->assertAvailable(
                $company,
                $professional,
                $service,
                $localStart,
                $snapshots['duration_minutes_snapshot'],
                $snapshots['buffer_before_minutes_snapshot'],
                $snapshots['buffer_after_minutes_snapshot'],
                isTattoo: (bool) ($data['is_tattoo'] ?? false),
                ignoreAppointmentConflicts: $ignoreConflicts,
                ignoreSlotAlignment: $ignoreSlotAlignment,
            )->assertAvailable();

            $authorizesOverlap = $flowKind === AppointmentFlowKind::FitIn
                || ($flowKind === AppointmentFlowKind::QuickAttendance && $conflicts->isNotEmpty());

            $appointment = new Appointment([
                'status' => AppointmentStatus::Confirmed,
                'origin' => AppointmentOrigin::Internal,
                'flow_kind' => $flowKind,
                'send_whatsapp_confirmation' => (bool) ($data['send_whatsapp_confirmation'] ?? true),
                'fit_in_reason' => $authorizesOverlap ? $fitInReason : null,
                'service_selection_mode' => $isOpenService ? 'to_be_defined' : 'defined',
                'is_tattoo' => (bool) ($data['is_tattoo'] ?? false),
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'service_name_snapshot' => $snapshots['service_name_snapshot'],
                'price_snapshot' => $snapshots['price_snapshot'],
                'duration_minutes_snapshot' => $snapshots['duration_minutes_snapshot'],
                'buffer_before_minutes_snapshot' => $snapshots['buffer_before_minutes_snapshot'],
                'buffer_after_minutes_snapshot' => $snapshots['buffer_after_minutes_snapshot'],
                'notes' => $data['notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
                'reference_key' => $data['reference_key'] ?? null,
                'appointment_reason' => $data['appointment_reason'] ?? null,
                'client_name_snapshot' => $client->name,
                'client_phone_snapshot' => $client->phone,
                'client_email_snapshot' => $client->email,
            ]);

            $appointment->company()->associate($company);
            $appointment->client()->associate($client);
            $appointment->professional()->associate($professional);
            if ($service !== null) {
                $appointment->service()->associate($service);
            }
            $appointment->creator()->associate($user);
            $appointment->confirmer()->associate($user);
            $appointment->confirmed_at = now();

            if ($authorizesOverlap) {
                $appointment->fitInAuthorizer()->associate($user);
                $appointment->fit_in_authorized_at = now();
            }

            $appointment->save();

            $historyAttributes = [
                'new_status' => $appointment->status,
                'new_start_at' => $appointment->start_at,
                'new_end_at' => $appointment->end_at,
            ];

            if ($flowKind !== AppointmentFlowKind::Normal) {
                $historyAttributes = array_merge(
                    $historyAttributes,
                    $this->flowHistoryAttributes($company, $flowKind, $conflicts, $fitInReason),
                );
            }

            $this->recordHistory($company, $user, $appointment, AppointmentHistoryType::Created, $historyAttributes);

            DB::afterCommit(fn () => event(new AppointmentCreated($appointment->refresh())));

            return $appointment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFitInAppointment(
        Company $company,
        User $user,
        Client $client,
        Professional $professional,
        ?Service $service,
        CarbonImmutable $localStart,
        array $data = [],
    ): Appointment {
        $this->assertCanBookException($user, $company);

        return $this->createInternalAppointment(
            $company,
            $user,
            $client,
            $professional,
            $service,
            $localStart,
            $data,
            ignoreAppointmentConflicts: true,
            flowKind: AppointmentFlowKind::FitIn,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createQuickAttendance(
        Company $company,
        User $user,
        Client $client,
        Professional $professional,
        ?Service $service,
        array $data = [],
    ): Appointment {
        $this->assertCanBookException($user, $company);

        return DB::transaction(function () use ($company, $user, $client, $professional, $service, $data): Appointment {
            $data['send_whatsapp_confirmation'] = (bool) ($data['send_whatsapp_confirmation'] ?? false);
            $localStart = CompanyDateTime::nowLocal($company);

            $appointment = $this->createInternalAppointment(
                $company,
                $user,
                $client,
                $professional,
                $service,
                $localStart,
                $data,
                ignoreSlotAlignment: true,
                flowKind: AppointmentFlowKind::QuickAttendance,
            );

            return app(AppointmentStatusService::class)->start($company, $user, $appointment);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAppointment(Company $company, User $user, Appointment $appointment, array $data): Appointment
    {
        return DB::transaction(function () use ($company, $user, $appointment, $data): Appointment {
            $this->ensureBelongsToCompany($company, $appointment);

            if (! in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Este agendamento não pode ser editado.',
                ]);
            }

            $oldStart = CarbonImmutable::parse($appointment->start_at);
            $oldEnd = CarbonImmutable::parse($appointment->end_at);
            $oldProfessionalId = (int) $appointment->professional_id;

            $client = isset($data['client_id'])
                ? Client::query()->findOrFail($data['client_id'])
                : $appointment->client;
            $isOpenService = ($data['service_selection_mode'] ?? $appointment->service_selection_mode) === 'to_be_defined'
                || blank($data['service_id'] ?? $appointment->service_id);
            $service = ! $isOpenService && isset($data['service_id'])
                ? Service::query()->findOrFail($data['service_id'])
                : $appointment->service;
            if ($isOpenService) {
                $service = null;
            }
            $professional = isset($data['professional_id'])
                ? Professional::query()->whereKey($data['professional_id'])->lockForUpdate()->firstOrFail()
                : Professional::query()->whereKey($appointment->professional_id)->lockForUpdate()->firstOrFail();

            $this->assertRelatedModels($company, $client, $professional, $service);

            $localStart = isset($data['appointment_date'], $data['appointment_time'])
                ? CompanyDateTime::parseLocal($company, $data['appointment_date'], $data['appointment_time'])
                : CompanyDateTime::utcToLocal($company, $appointment->start_at);

            $snapshots = $isOpenService
                ? $this->resolveOpenServiceSnapshots($data, $appointment)
                : $this->resolveSnapshots($company, $professional, $service);
            $startUtc = CompanyDateTime::localToUtc($company, $localStart);
            $endUtc = $startUtc->addMinutes($snapshots['duration_minutes_snapshot']);
            $isTattoo = $appointment->tattooRequest()->exists() || (bool) ($data['is_tattoo'] ?? $appointment->is_tattoo);
            $conflicts = $this->conflictService->conflictingAppointments(
                $company,
                $professional,
                $startUtc,
                $endUtc,
                $snapshots['buffer_before_minutes_snapshot'],
                $snapshots['buffer_after_minutes_snapshot'],
                $appointment,
            );
            $scheduleWillChange = ! $oldStart->equalTo($startUtc)
                || ! $oldEnd->equalTo($endUtc)
                || $oldProfessionalId !== (int) $professional->getKey();
            $isFitIn = $appointment->flow_kind === AppointmentFlowKind::FitIn;
            $newFitInReason = $this->normalizedFitInReason($data['fit_in_reason'] ?? null, AppointmentFlowKind::Normal);

            if ($isFitIn && $scheduleWillChange && $conflicts->isNotEmpty() && $newFitInReason === null) {
                throw ValidationException::withMessages([
                    'fit_in_reason' => 'O novo horário conflita com outro compromisso. Informe o motivo para autorizar a sobreposição.',
                ]);
            }

            $this->availabilityService->assertAvailable(
                $company,
                $professional,
                $service,
                $localStart,
                $snapshots['duration_minutes_snapshot'],
                $snapshots['buffer_before_minutes_snapshot'],
                $snapshots['buffer_after_minutes_snapshot'],
                $appointment,
                $isTattoo,
                ignoreAppointmentConflicts: $isFitIn && $conflicts->isNotEmpty(),
            )->assertAvailable();

            $appointment->fill([
                'client_id' => $client->getKey(),
                'professional_id' => $professional->getKey(),
                'service_id' => $service?->getKey(),
                'service_selection_mode' => $isOpenService ? 'to_be_defined' : 'defined',
                'is_tattoo' => $isTattoo,
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'service_name_snapshot' => $snapshots['service_name_snapshot'],
                'price_snapshot' => $snapshots['price_snapshot'],
                'duration_minutes_snapshot' => $snapshots['duration_minutes_snapshot'],
                'buffer_before_minutes_snapshot' => $snapshots['buffer_before_minutes_snapshot'],
                'buffer_after_minutes_snapshot' => $snapshots['buffer_after_minutes_snapshot'],
                'notes' => $data['notes'] ?? $appointment->notes,
                'internal_notes' => $data['internal_notes'] ?? $appointment->internal_notes,
                'appointment_reason' => $data['appointment_reason'] ?? $appointment->appointment_reason,
                'updated_by' => $user->getKey(),
            ]);

            $reauthorizedOverlap = $isFitIn && $scheduleWillChange && $conflicts->isNotEmpty();

            if ($reauthorizedOverlap) {
                $appointment->fit_in_reason = $newFitInReason;
                $appointment->fitInAuthorizer()->associate($user);
                $appointment->fit_in_authorized_at = now();
            }

            $appointment->save();

            $historyAttributes = [
                'old_status' => $appointment->status,
                'new_status' => $appointment->status,
                'old_start_at' => $oldStart,
                'new_start_at' => $appointment->start_at,
                'old_end_at' => $oldEnd,
                'new_end_at' => $appointment->end_at,
            ];

            if ($reauthorizedOverlap) {
                $historyAttributes = array_merge(
                    $historyAttributes,
                    $this->flowHistoryAttributes($company, AppointmentFlowKind::FitIn, $conflicts, $newFitInReason),
                );
            }

            $this->recordHistory($company, $user, $appointment, AppointmentHistoryType::Updated, $historyAttributes);

            $scheduleChanged = ! $oldStart->equalTo(CarbonImmutable::parse($appointment->start_at))
                || ! $oldEnd->equalTo(CarbonImmutable::parse($appointment->end_at))
                || $oldProfessionalId !== (int) $appointment->professional_id;

            if ($scheduleChanged) {
                DB::afterCommit(fn () => event(new AppointmentRescheduled(
                    $appointment->refresh(),
                    $oldStart,
                    $oldEnd,
                    'internal',
                )));
            }

            return $appointment->refresh();
        });
    }

    public function reschedule(
        Company $company,
        User $user,
        Appointment $appointment,
        CarbonImmutable $localStart,
        ?Professional $professional = null,
    ): Appointment {
        return DB::transaction(function () use ($company, $user, $appointment, $localStart, $professional): Appointment {
            $this->ensureBelongsToCompany($company, $appointment);

            if (! $appointment->canBeRescheduled()) {
                throw ValidationException::withMessages([
                    'start_at' => 'Este agendamento não pode ser remarcado.',
                ]);
            }

            $professional ??= $appointment->professional;
            $ids = collect([(int) $appointment->professional_id, (int) $professional->getKey()])->unique()->sort()->values();
            $professionals = Professional::query()->whereIn('id', $ids)->lockForUpdate()->get();

            $professional = $professionals->firstWhere('id', $professional->getKey()) ?? $professional;

            $this->assertRelatedModels($company, $appointment->client, $professional, $appointment->service);

            $oldStart = CarbonImmutable::parse($appointment->start_at);
            $oldEnd = CarbonImmutable::parse($appointment->end_at);
            $startUtc = CompanyDateTime::localToUtc($company, $localStart);
            $endUtc = $startUtc->addMinutes($appointment->duration_minutes_snapshot);

            $this->availabilityService->assertAvailable(
                $company,
                $professional,
                $appointment->service,
                $localStart,
                $appointment->duration_minutes_snapshot,
                $appointment->buffer_before_minutes_snapshot,
                $appointment->buffer_after_minutes_snapshot,
                $appointment,
                (bool) $appointment->is_tattoo,
            )->assertAvailable();

            $appointment->update([
                'professional_id' => $professional->getKey(),
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'updated_by' => $user->getKey(),
            ]);

            $this->recordHistory($company, $user, $appointment, AppointmentHistoryType::Rescheduled, [
                'old_status' => $appointment->status,
                'new_status' => $appointment->status,
                'old_start_at' => $oldStart,
                'new_start_at' => $appointment->start_at,
                'old_end_at' => $oldEnd,
                'new_end_at' => $appointment->end_at,
            ]);

            DB::afterCommit(fn () => event(new AppointmentRescheduled(
                $appointment->refresh(),
                $oldStart,
                $oldEnd,
                'internal',
            )));

            return $appointment->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function fetchCalendarEvents(
        Company $company,
        User $user,
        CarbonImmutable $visibleStartUtc,
        CarbonImmutable $visibleEndUtc,
        array $filters = [],
    ): array {
        $query = Appointment::query()
            ->where('company_id', $company->getKey())
            ->with(['client', 'service', 'professional'])
            ->inPeriod($visibleStartUtc, $visibleEndUtc);

        $this->applyCalendarAuthorization($query, $company, $user);
        $this->applyCalendarFilters($query, $filters);

        $appointments = $query
            ->orderBy('start_at')
            ->get()
            ->map(fn (Appointment $appointment): array => $this->formatCalendarEvent($company, $user, $appointment))
            ->all();

        $blocks = $this->fetchCalendarBlocks($company, $user, $visibleStartUtc, $visibleEndUtc, $filters)
            ->map(fn (ScheduleBlock $block): array => $this->formatCalendarBlock($company, $block))
            ->all();

        return collect([...$appointments, ...$blocks])
            ->sortBy('start')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createFromSelection(Company $company, User $user, array $data): Appointment
    {
        $client = Client::query()->findOrFail($data['client_id']);
        $service = filled($data['service_id'] ?? null) ? Service::query()->findOrFail($data['service_id']) : null;
        $professional = Professional::query()->findOrFail($data['professional_id']);
        $localStart = CompanyDateTime::parseLocal($company, $data['appointment_date'], $data['appointment_time']);

        $overlaps = $this->formSlotHasOverlap(
            $company,
            (int) $professional->getKey(),
            $service?->getKey(),
            filled($data['duration_minutes_snapshot'] ?? null) ? (int) $data['duration_minutes_snapshot'] : null,
            (string) $data['appointment_date'],
            (string) $data['appointment_time'],
            false,
        );

        if ($overlaps) {
            return $this->createFitInAppointment(
                $company,
                $user,
                $client,
                $professional,
                $service,
                $localStart,
                $data,
            );
        }

        return $this->createInternalAppointment(
            $company,
            $user,
            $client,
            $professional,
            $service,
            $localStart,
            $data,
        );
    }

    /**
     * @return array{success: bool, message?: string}
     */
    public function rescheduleFromDrag(
        Company $company,
        User $user,
        Appointment $appointment,
        CarbonImmutable $localStart,
        ?int $professionalId = null,
    ): array {
        try {
            $professional = $professionalId
                ? Professional::query()->findOrFail($professionalId)
                : null;

            $this->reschedule($company, $user, $appointment, $localStart, $professional);

            return ['success' => true];
        } catch (ValidationException $exception) {
            return [
                'success' => false,
                'message' => collect($exception->errors())->flatten()->first()
                    ?? 'Não foi possível remarcar o agendamento.',
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>|null  $metadata
     */
    public function recordPublicHistory(
        Company $company,
        Appointment $appointment,
        AppointmentHistoryType $type,
        array $attributes = [],
        ?array $metadata = null,
    ): void {
        $history = new AppointmentHistory([
            'type' => $type,
            ...$attributes,
            'metadata' => $metadata,
        ]);
        $history->company()->associate($company);
        $history->appointment()->associate($appointment);
        $history->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function recordHistory(
        Company $company,
        User $user,
        Appointment $appointment,
        AppointmentHistoryType $type,
        array $attributes = [],
    ): void {
        $history = new AppointmentHistory([
            'type' => $type,
            ...$attributes,
        ]);
        $history->company()->associate($company);
        $history->appointment()->associate($appointment);
        $history->user()->associate($user);
        $history->save();
    }

    public function ensureBelongsToCompany(Company $company, Appointment $appointment): void
    {
        if ((int) $appointment->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    protected function assertRelatedModels(
        Company $company,
        Client $client,
        Professional $professional,
        ?Service $service,
    ): void {
        if ((int) $client->company_id !== (int) $company->getKey()
            || (int) $professional->company_id !== (int) $company->getKey()
            || ($service !== null && (int) $service->company_id !== (int) $company->getKey())) {
            abort(404);
        }

        if (! $client->is_active || ! $professional->is_active || ($service !== null && ! $service->is_active)) {
            throw ValidationException::withMessages([
                'professional_id' => 'Cliente, profissional ou serviço inativo.',
            ]);
        }

        if (! $professional->is_bookable || ($service !== null && ! $service->is_bookable)) {
            throw ValidationException::withMessages([
                'professional_id' => 'Profissional ou serviço indisponível para agendamento.',
            ]);
        }

        if ($service === null) {
            return;
        }

        $linked = $professional->services()
            ->where('services.id', $service->getKey())
            ->wherePivot('is_active', true)
            ->exists();

        if (! $linked) {
            throw ValidationException::withMessages([
                'professional_id' => 'Profissional não está associado a este serviço.',
            ]);
        }
    }

    protected function resolveSnapshots(Company $company, Professional $professional, Service $service): array
    {
        return $this->snapshotResolver->resolve($company, $professional, $service);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{service_name_snapshot: string, price_snapshot: null, duration_minutes_snapshot: int, buffer_before_minutes_snapshot: int, buffer_after_minutes_snapshot: int}
     */
    protected function resolveOpenServiceSnapshots(array $data, ?Appointment $appointment = null): array
    {
        $duration = (int) ($data['duration_minutes_snapshot'] ?? $appointment?->duration_minutes_snapshot ?? 0);

        if ($duration < 15 || $duration > 480) {
            throw ValidationException::withMessages([
                'duration_minutes_snapshot' => 'Informe uma duração entre 15 e 480 minutos.',
            ]);
        }

        return [
            'service_name_snapshot' => 'A definir no atendimento',
            'price_snapshot' => null,
            'duration_minutes_snapshot' => $duration,
            'buffer_before_minutes_snapshot' => (int) ($data['buffer_before_minutes_snapshot'] ?? $appointment?->buffer_before_minutes_snapshot ?? 0),
            'buffer_after_minutes_snapshot' => (int) ($data['buffer_after_minutes_snapshot'] ?? $appointment?->buffer_after_minutes_snapshot ?? 0),
        ];
    }

    /**
     * @return array{
     *     service_name_snapshot: string,
     *     price_snapshot: string,
     *     duration_minutes_snapshot: int,
     *     buffer_before_minutes_snapshot: int,
     *     buffer_after_minutes_snapshot: int
     * }
     */
    public function resolveSnapshotsPublic(Company $company, Professional $professional, Service $service): array
    {
        return $this->snapshotResolver->resolve($company, $professional, $service);
    }

    /**
     * @param  Builder<Appointment>  $query
     * @param  array<string, mixed>  $filters
     */
    protected function applyCalendarFilters(Builder $query, array $filters): void
    {
        if (filled($filters['professional_id'] ?? null)) {
            $query->where('professional_id', $filters['professional_id']);
        }

        if (filled($filters['service_id'] ?? null)) {
            $query->where('service_id', $filters['service_id']);
        }

        if (filled($filters['status'] ?? null)) {
            $query->where('status', $filters['status']);
        }
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    protected function applyCalendarAuthorization(Builder $query, Company $company, User $user): void
    {
        if ($user->is_super_admin || $user->hasActiveRoleInCompany(
            $company,
            CompanyRole::CompanyAdmin,
            CompanyRole::Manager,
        )) {
            return;
        }

        $professionalId = Professional::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->value('id');

        if (! $professionalId) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('professional_id', $professionalId);
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatCalendarEvent(Company $company, User $user, Appointment $appointment): array
    {
        $localStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);
        $localEnd = CompanyDateTime::utcToLocal($company, $appointment->end_at);

        $title = "{$appointment->client->name} — ".($appointment->service_name_snapshot ?? 'A definir no atendimento');

        if ($user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin, CompanyRole::Manager) || $user->is_super_admin) {
            $title .= " + {$appointment->professional->name}";
        }

        $markedAsFitIn = $appointment->isMarkedAsFitIn();

        if ($markedAsFitIn) {
            $title = 'Encaixe · '.$title;
        }

        return [
            'id' => (string) $appointment->getKey(),
            'title' => $title,
            'start' => $localStart->toIso8601String(),
            'end' => $localEnd->toIso8601String(),
            'backgroundColor' => $this->resolveEventColor($appointment),
            'borderColor' => $markedAsFitIn ? '#d97706' : $this->resolveEventColor($appointment),
            'classNames' => $markedAsFitIn ? ['fc-event-fit-in'] : [],
            'extendedProps' => [
                'status' => $appointment->status->value,
                'statusLabel' => $appointment->status->label(),
                'flowKind' => $appointment->flow_kind?->value,
                'fitIn' => $markedAsFitIn,
                'client' => $appointment->client->name,
                'service' => $appointment->service_name_snapshot,
                'professional' => $appointment->professional->name,
                'editable' => $appointment->canBeRescheduled(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formatCalendarBlock(Company $company, ScheduleBlock $block): array
    {
        $localStart = CompanyDateTime::utcToLocal($company, $block->start_at);
        $localEnd = CompanyDateTime::utcToLocal($company, $block->end_at);
        $scope = $block->professional?->name ?? 'Toda a empresa';

        return [
            'id' => 'block-'.$block->getKey(),
            'title' => "Bloqueio: {$block->title} · {$scope}",
            'start' => $localStart->toIso8601String(),
            'end' => $localEnd->toIso8601String(),
            'allDay' => $block->is_all_day,
            'backgroundColor' => '#64748b',
            'borderColor' => '#475569',
            'textColor' => '#ffffff',
            'extendedProps' => [
                'type' => 'schedule_block',
                'blockType' => $block->type->value,
                'blockTypeLabel' => $block->type->label(),
                'professional' => $scope,
                'editable' => false,
            ],
        ];
    }

    protected function resolveEventColor(Appointment $appointment): string
    {
        return match ($appointment->status) {
            AppointmentStatus::Cancelled => '#9ca3af',
            AppointmentStatus::NoShow => '#ef4444',
            AppointmentStatus::Pending => '#f59e0b',
            AppointmentStatus::InProgress => '#3b82f6',
            AppointmentStatus::Completed => '#22c55e',
            AppointmentStatus::Confirmed => $appointment->service?->color
                ?? $appointment->professional?->color
                ?? '#6366f1',
        };
    }

    /**
     * @return Collection<int, ScheduleBlock>
     */
    public function fetchCalendarBlocks(
        Company $company,
        User $user,
        CarbonImmutable $visibleStartUtc,
        CarbonImmutable $visibleEndUtc,
        array $filters = [],
    ): Collection {
        $query = ScheduleBlock::query()
            ->where('company_id', $company->getKey())
            ->active()
            ->inPeriod($visibleStartUtc, $visibleEndUtc)
            ->with(['professional']);

        $this->applyCalendarBlockAuthorization($query, $company, $user);

        if (filled($filters['professional_id'] ?? null)) {
            $query->where(function (Builder $builder) use ($filters): void {
                $builder
                    ->whereNull('professional_id')
                    ->orWhere('professional_id', $filters['professional_id']);
            });
        }

        return $query->get();
    }

    /**
     * @param  Builder<ScheduleBlock>  $query
     */
    protected function applyCalendarBlockAuthorization(Builder $query, Company $company, User $user): void
    {
        if ($user->is_super_admin || $user->hasActiveRoleInCompany(
            $company,
            CompanyRole::CompanyAdmin,
            CompanyRole::Manager,
        )) {
            return;
        }

        $professionalId = Professional::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->value('id');

        if (! $professionalId) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $builder) use ($professionalId): void {
            $builder
                ->whereNull('professional_id')
                ->orWhere('professional_id', $professionalId);
        });
    }

    public function formWindowLabel(
        Company $company,
        ?int $professionalId,
        ?int $serviceId,
        ?int $durationMinutes,
        ?string $date,
        ?string $time,
        bool $useNow,
    ): string {
        $slot = $this->resolveFormSlot($company, $professionalId, $serviceId, $durationMinutes, $date, $time, $useNow);

        if ($slot === null) {
            if ($useNow) {
                return CompanyDateTime::nowLocal($company)->format('d/m/Y H:i:s').' · informe a duração para ver o fim previsto';
            }

            return 'Informe o profissional e o horário.';
        }

        $localStart = CompanyDateTime::utcToLocal($company, $slot['start_utc']);
        $localEnd = CompanyDateTime::utcToLocal($company, $slot['end_utc']);
        $format = $useNow ? 'd/m/Y H:i:s' : 'd/m/Y H:i';

        return $localStart->format($format).' até '.$localEnd->format('H:i');
    }

    public function overlapSummaryHtml(
        Company $company,
        ?int $professionalId,
        ?int $serviceId,
        ?int $durationMinutes,
        ?string $date,
        ?string $time,
        bool $useNow,
        ?int $ignoreAppointmentId = null,
    ): string {
        $slot = $this->resolveFormSlot($company, $professionalId, $serviceId, $durationMinutes, $date, $time, $useNow);

        if ($slot === null) {
            return 'Informe o profissional e o horário para ver os compromissos afetados.';
        }

        $ignore = $ignoreAppointmentId
            ? Appointment::query()->where('company_id', $company->getKey())->find($ignoreAppointmentId)
            : null;

        $conflicts = $this->conflictService->conflictingAppointments(
            $company,
            $slot['professional'],
            $slot['start_utc'],
            $slot['end_utc'],
            $slot['buffer_before'],
            $slot['buffer_after'],
            $ignore,
        );

        if ($conflicts->isEmpty()) {
            return 'Nenhum outro compromisso neste horário.';
        }

        $items = $conflicts
            ->map(fn (Appointment $existing): string => '<li>'.e($this->conflictLine($company, $existing)).'</li>')
            ->implode('');

        return 'Estes compromissos serão mantidos junto com este horário:<ul>'.$items.'</ul>';
    }

    public function formSlotHasOverlap(
        Company $company,
        ?int $professionalId,
        ?int $serviceId,
        ?int $durationMinutes,
        ?string $date,
        ?string $time,
        bool $useNow,
        ?int $ignoreAppointmentId = null,
    ): bool {
        $slot = $this->resolveFormSlot($company, $professionalId, $serviceId, $durationMinutes, $date, $time, $useNow);

        if ($slot === null) {
            return false;
        }

        $ignore = $ignoreAppointmentId
            ? Appointment::query()->where('company_id', $company->getKey())->find($ignoreAppointmentId)
            : null;

        return $this->conflictService->conflictingAppointments(
            $company,
            $slot['professional'],
            $slot['start_utc'],
            $slot['end_utc'],
            $slot['buffer_before'],
            $slot['buffer_after'],
            $ignore,
        )->isNotEmpty();
    }

    protected function assertCanBookException(User $user, Company $company): void
    {
        abort_unless($user->is_active, 403);

        if ($user->is_super_admin) {
            abort_unless($user->canAccessTenant($company), 403);

            return;
        }

        abort_unless($user->hasActiveRoleInCompany(
            $company,
            CompanyRole::CompanyAdmin,
            CompanyRole::Manager,
            CompanyRole::Receptionist,
        ), 403);
    }

    protected function normalizedFitInReason(mixed $reason, AppointmentFlowKind $flowKind): ?string
    {
        $reason = trim((string) ($reason ?? ''));

        if ($reason === '') {
            if ($flowKind === AppointmentFlowKind::FitIn) {
                throw ValidationException::withMessages([
                    'fit_in_reason' => 'Informe o motivo do encaixe.',
                ]);
            }

            return null;
        }

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'fit_in_reason' => 'O motivo do encaixe deve ter no máximo 500 caracteres.',
            ]);
        }

        return $reason;
    }

    /**
     * @param  Collection<int, Appointment>  $conflicts
     * @return array{description: string, metadata: array<string, mixed>}
     */
    protected function flowHistoryAttributes(
        Company $company,
        AppointmentFlowKind $flowKind,
        Collection $conflicts,
        ?string $reason,
    ): array {
        $hadConflict = $conflicts->isNotEmpty();
        $summary = $conflicts
            ->map(fn (Appointment $existing): string => $this->conflictLine($company, $existing))
            ->implode('; ');

        $description = match (true) {
            $flowKind === AppointmentFlowKind::FitIn && $hadConflict => 'Encaixe autorizado. Compromissos afetados: '.$summary.'.',
            $flowKind === AppointmentFlowKind::FitIn => 'Encaixe autorizado sem conflito no momento de salvar.',
            $hadConflict => 'Atendimento rápido com encaixe autorizado. Compromissos afetados: '.$summary.'.',
            default => 'Atendimento rápido registrado no horário atual.',
        };

        return [
            'description' => $description,
            'metadata' => [
                'flow_kind' => $flowKind->value,
                'fit_in_reason' => $reason,
                'had_conflict' => $hadConflict,
                'conflicts' => $conflicts->map(fn (Appointment $existing): array => [
                    'id' => $existing->getKey(),
                    'client_name' => $existing->client?->name,
                    'service_name' => $existing->service_name_snapshot,
                    'start_at' => CarbonImmutable::parse($existing->start_at)->toIso8601String(),
                    'end_at' => CarbonImmutable::parse($existing->end_at)->toIso8601String(),
                    'buffer_before_minutes' => (int) $existing->buffer_before_minutes_snapshot,
                    'buffer_after_minutes' => (int) $existing->buffer_after_minutes_snapshot,
                ])->values()->all(),
            ],
        ];
    }

    protected function conflictLine(Company $company, Appointment $existing): string
    {
        $start = CompanyDateTime::formatLocal($company, $existing->start_at, 'd/m/Y H:i');
        $end = CompanyDateTime::formatLocal($company, $existing->end_at, 'H:i');
        $client = $existing->client?->name ?? 'Cliente';
        $service = $existing->service_name_snapshot ?: 'A definir no atendimento';
        $line = "{$client} · {$service} · {$start}–{$end}";
        $before = (int) $existing->buffer_before_minutes_snapshot;
        $after = (int) $existing->buffer_after_minutes_snapshot;

        if ($before > 0 || $after > 0) {
            $line .= " (preparação {$before} min, finalização {$after} min)";
        }

        return $line;
    }

    /**
     * @return array{
     *     professional: Professional,
     *     start_utc: CarbonImmutable,
     *     end_utc: CarbonImmutable,
     *     buffer_before: int,
     *     buffer_after: int
     * }|null
     */
    protected function resolveFormSlot(
        Company $company,
        ?int $professionalId,
        ?int $serviceId,
        ?int $durationMinutes,
        ?string $date,
        ?string $time,
        bool $useNow,
    ): ?array {
        if ($professionalId === null) {
            return null;
        }

        $professional = Professional::query()
            ->where('company_id', $company->getKey())
            ->find($professionalId);

        if ($professional === null) {
            return null;
        }

        if ($useNow) {
            $localStart = CompanyDateTime::nowLocal($company);
        } elseif (filled($date) && filled($time)) {
            try {
                $localStart = CompanyDateTime::parseLocal($company, (string) $date, (string) $time);
            } catch (\Throwable) {
                return null;
            }
        } else {
            return null;
        }

        if ($serviceId !== null) {
            $service = Service::query()
                ->where('company_id', $company->getKey())
                ->find($serviceId);

            if ($service === null) {
                return null;
            }

            $snapshots = $this->snapshotResolver->resolve($company, $professional, $service);
            $duration = $snapshots['duration_minutes_snapshot'];
            $bufferBefore = $snapshots['buffer_before_minutes_snapshot'];
            $bufferAfter = $snapshots['buffer_after_minutes_snapshot'];
        } else {
            $duration = (int) $durationMinutes;
            $bufferBefore = 0;
            $bufferAfter = 0;

            if ($duration < 15) {
                return null;
            }
        }

        $startUtc = CompanyDateTime::localToUtc($company, $localStart);

        return [
            'professional' => $professional,
            'start_utc' => $startUtc,
            'end_utc' => $startUtc->addMinutes($duration),
            'buffer_before' => $bufferBefore,
            'buffer_after' => $bufferAfter,
        ];
    }
}
