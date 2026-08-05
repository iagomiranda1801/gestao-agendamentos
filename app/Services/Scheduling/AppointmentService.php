<?php

namespace App\Services\Scheduling;

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
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createInternalAppointment(
        Company $company,
        User $user,
        Client $client,
        Professional $professional,
        Service $service,
        CarbonImmutable $localStart,
        array $data = [],
    ): Appointment {
        return DB::transaction(function () use ($company, $user, $client, $professional, $service, $localStart, $data): Appointment {
            $professional = Professional::query()->whereKey($professional->getKey())->lockForUpdate()->firstOrFail();

            $this->assertRelatedModels($company, $client, $professional, $service);

            $snapshots = $this->resolveSnapshots($company, $professional, $service);
            $startUtc = CompanyDateTime::localToUtc($company, $localStart);
            $endUtc = $startUtc->addMinutes($snapshots['duration_minutes_snapshot']);

            $this->availabilityService->assertAvailable(
                $company,
                $professional,
                $service,
                $localStart,
                $snapshots['duration_minutes_snapshot'],
                $snapshots['buffer_before_minutes_snapshot'],
                $snapshots['buffer_after_minutes_snapshot'],
            )->assertAvailable();

            $appointment = new Appointment([
                'status' => AppointmentStatus::Confirmed,
                'origin' => AppointmentOrigin::Internal,
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
                'client_name_snapshot' => $client->name,
                'client_phone_snapshot' => $client->phone,
                'client_email_snapshot' => $client->email,
            ]);

            $appointment->company()->associate($company);
            $appointment->client()->associate($client);
            $appointment->professional()->associate($professional);
            $appointment->service()->associate($service);
            $appointment->creator()->associate($user);
            $appointment->confirmer()->associate($user);
            $appointment->confirmed_at = now();
            $appointment->save();

            $this->recordHistory($company, $user, $appointment, AppointmentHistoryType::Created, [
                'new_status' => $appointment->status,
                'new_start_at' => $appointment->start_at,
                'new_end_at' => $appointment->end_at,
            ]);

            DB::afterCommit(fn () => event(new AppointmentCreated($appointment->refresh())));

            return $appointment->refresh();
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
            $service = isset($data['service_id'])
                ? Service::query()->findOrFail($data['service_id'])
                : $appointment->service;
            $professional = isset($data['professional_id'])
                ? Professional::query()->whereKey($data['professional_id'])->lockForUpdate()->firstOrFail()
                : Professional::query()->whereKey($appointment->professional_id)->lockForUpdate()->firstOrFail();

            $this->assertRelatedModels($company, $client, $professional, $service);

            $localStart = isset($data['appointment_date'], $data['appointment_time'])
                ? CompanyDateTime::parseLocal($company, $data['appointment_date'], $data['appointment_time'])
                : CompanyDateTime::utcToLocal($company, $appointment->start_at);

            $snapshots = $this->resolveSnapshots($company, $professional, $service);
            $startUtc = CompanyDateTime::localToUtc($company, $localStart);
            $endUtc = $startUtc->addMinutes($snapshots['duration_minutes_snapshot']);

            $this->availabilityService->assertAvailable(
                $company,
                $professional,
                $service,
                $localStart,
                $snapshots['duration_minutes_snapshot'],
                $snapshots['buffer_before_minutes_snapshot'],
                $snapshots['buffer_after_minutes_snapshot'],
                $appointment,
            )->assertAvailable();

            $appointment->fill([
                'client_id' => $client->getKey(),
                'professional_id' => $professional->getKey(),
                'service_id' => $service->getKey(),
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'service_name_snapshot' => $snapshots['service_name_snapshot'],
                'price_snapshot' => $snapshots['price_snapshot'],
                'duration_minutes_snapshot' => $snapshots['duration_minutes_snapshot'],
                'buffer_before_minutes_snapshot' => $snapshots['buffer_before_minutes_snapshot'],
                'buffer_after_minutes_snapshot' => $snapshots['buffer_after_minutes_snapshot'],
                'notes' => $data['notes'] ?? $appointment->notes,
                'internal_notes' => $data['internal_notes'] ?? $appointment->internal_notes,
                'updated_by' => $user->getKey(),
            ]);
            $appointment->save();

            $this->recordHistory($company, $user, $appointment, AppointmentHistoryType::Updated, [
                'old_status' => $appointment->status,
                'new_status' => $appointment->status,
                'old_start_at' => $oldStart,
                'new_start_at' => $appointment->start_at,
                'old_end_at' => $oldEnd,
                'new_end_at' => $appointment->end_at,
            ]);

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
        $service = Service::query()->findOrFail($data['service_id']);
        $professional = Professional::query()->findOrFail($data['professional_id']);
        $localStart = CompanyDateTime::parseLocal($company, $data['appointment_date'], $data['appointment_time']);

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
        Service $service,
    ): void {
        if ((int) $client->company_id !== (int) $company->getKey()
            || (int) $professional->company_id !== (int) $company->getKey()
            || (int) $service->company_id !== (int) $company->getKey()) {
            abort(404);
        }

        if (! $client->is_active || ! $professional->is_active || ! $service->is_active) {
            throw ValidationException::withMessages([
                'status' => 'Cliente, profissional ou serviço inativo.',
            ]);
        }

        if (! $professional->is_bookable || ! $service->is_bookable) {
            throw ValidationException::withMessages([
                'status' => 'Profissional ou serviço indisponível para agendamento.',
            ]);
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

        $title = "{$appointment->client->name} — {$appointment->service_name_snapshot}";

        if ($user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin, CompanyRole::Manager) || $user->is_super_admin) {
            $title .= " + {$appointment->professional->name}";
        }

        return [
            'id' => (string) $appointment->getKey(),
            'title' => $title,
            'start' => $localStart->toIso8601String(),
            'end' => $localEnd->toIso8601String(),
            'backgroundColor' => $this->resolveEventColor($appointment),
            'borderColor' => $this->resolveEventColor($appointment),
            'extendedProps' => [
                'status' => $appointment->status->value,
                'statusLabel' => $appointment->status->label(),
                'client' => $appointment->client->name,
                'service' => $appointment->service_name_snapshot,
                'professional' => $appointment->professional->name,
                'editable' => $appointment->canBeRescheduled(),
                'viewUrl' => route('filament.app.resources.agendamentos.view', [
                    'tenant' => $company,
                    'record' => $appointment,
                ]),
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
}
