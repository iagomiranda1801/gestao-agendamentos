<?php

namespace App\Services\Scheduling;

use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentConfirmed;
use App\Models\Appointment;
use App\Models\AppointmentHistory;
use App\Models\Company;
use App\Models\User;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentStatusService
{
    public function confirm(Company $company, User $user, Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($company, $user, $appointment): Appointment {
            app(AppointmentService::class)->ensureBelongsToCompany($company, $appointment);

            if (! $appointment->canBeConfirmed()) {
                throw ValidationException::withMessages([
                    'status' => 'Somente agendamentos aguardando confirmação podem ser confirmados.',
                ]);
            }

            $oldStatus = $appointment->status;
            $appointment->status = AppointmentStatus::Confirmed;
            $appointment->confirmed_by = $user->getKey();
            $appointment->confirmed_at = now();
            $appointment->save();

            $this->recordHistory($company, $user, $appointment, AppointmentHistoryType::Confirmed, $oldStatus);

            DB::afterCommit(fn () => event(new AppointmentConfirmed($appointment->refresh())));

            return $appointment->refresh();
        });
    }

    public function start(Company $company, User $user, Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($company, $user, $appointment): Appointment {
            app(AppointmentService::class)->ensureBelongsToCompany($company, $appointment);

            if ($appointment->status !== AppointmentStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'status' => 'Somente agendamentos confirmados podem ser iniciados.',
                ]);
            }

            $oldStatus = $appointment->status;
            $appointment->status = AppointmentStatus::InProgress;
            $appointment->started_by = $user->getKey();
            $appointment->started_at = now();
            $appointment->save();

            $this->recordHistory($company, $user, $appointment, AppointmentHistoryType::Started, $oldStatus);

            return $appointment->refresh();
        });
    }

    public function cancel(Company $company, User $user, Appointment $appointment, string $reason): Appointment
    {
        return DB::transaction(function () use ($company, $user, $appointment, $reason): Appointment {
            app(AppointmentService::class)->ensureBelongsToCompany($company, $appointment);

            if (! $appointment->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'status' => 'Este agendamento não pode ser cancelado.',
                ]);
            }

            if (blank($reason)) {
                throw ValidationException::withMessages([
                    'cancellation_reason' => 'Informe o motivo do cancelamento.',
                ]);
            }

            $oldStatus = $appointment->status;
            $appointment->status = AppointmentStatus::Cancelled;
            $appointment->cancellation_reason = $reason;
            $appointment->cancelled_by = $user->getKey();
            $appointment->cancelled_at = now();
            $appointment->save();

            $this->recordHistory($company, $user, $appointment, AppointmentHistoryType::Cancelled, $oldStatus);

            $oldStart = CarbonImmutable::parse($appointment->start_at);
            DB::afterCommit(fn () => event(new AppointmentCancelled($appointment->refresh(), $oldStart, 'internal')));

            return $appointment->refresh();
        });
    }

    public function markNoShow(Company $company, User $user, Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($company, $user, $appointment): Appointment {
            app(AppointmentService::class)->ensureBelongsToCompany($company, $appointment);

            if (! in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)) {
                throw ValidationException::withMessages([
                    'status' => 'Este agendamento não pode ser marcado como não compareceu.',
                ]);
            }

            if (CompanyDateTime::utcToLocal($company, $appointment->start_at)->isFuture()) {
                throw ValidationException::withMessages([
                    'status' => 'Só é possível marcar falta após o horário previsto.',
                ]);
            }

            $oldStatus = $appointment->status;
            $appointment->status = AppointmentStatus::NoShow;
            $appointment->no_show_at = now();
            $appointment->save();

            $this->recordHistory($company, $user, $appointment, AppointmentHistoryType::NoShow, $oldStatus);

            return $appointment->refresh();
        });
    }

    protected function recordHistory(
        Company $company,
        User $user,
        Appointment $appointment,
        AppointmentHistoryType $type,
        AppointmentStatus $oldStatus,
        ?CarbonImmutable $oldStart = null,
        ?CarbonImmutable $oldEnd = null,
        ?string $description = null,
    ): void {
        $history = new AppointmentHistory([
            'type' => $type,
            'old_status' => $oldStatus,
            'new_status' => $appointment->status,
            'old_start_at' => $oldStart,
            'new_start_at' => $appointment->start_at,
            'old_end_at' => $oldEnd,
            'new_end_at' => $appointment->end_at,
            'description' => $description,
        ]);
        $history->company()->associate($company);
        $history->appointment()->associate($appointment);
        $history->user()->associate($user);
        $history->save();
    }
}
