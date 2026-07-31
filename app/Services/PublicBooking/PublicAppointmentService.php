<?php

namespace App\Services\PublicBooking;

use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentRescheduled;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\CompanySchedulingSetting;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Support\CompanyDateTime;
use App\Support\PublicBookingTextSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PublicAppointmentService
{
    public function __construct(
        protected CompanySchedulingSettingService $settingsService,
        protected AvailabilityService $availabilityService,
        protected AppointmentService $appointmentService,
        protected PublicAppointmentTokenService $tokenService,
    ) {}

    public function cancelPublic(Company $company, Appointment $appointment, string $reason): Appointment
    {
        $settings = $this->settingsService->getOrCreate($company);
        $reason = PublicBookingTextSanitizer::cancellationReason($reason) ?? '';

        $this->assertPublicAppointment($company, $appointment);
        $this->assertCancellationAllowed($company, $settings, $appointment);

        if (blank($reason)) {
            throw ValidationException::withMessages([
                'cancellation_reason' => 'Informe o motivo do cancelamento.',
            ]);
        }

        return DB::transaction(function () use ($company, $appointment, $reason): Appointment {
            $this->appointmentService->ensureBelongsToCompany($company, $appointment->refresh());

            if (! $appointment->canBeCancelled()) {
                throw ValidationException::withMessages([
                    'status' => 'Este agendamento não pode ser cancelado.',
                ]);
            }

            $oldStatus = $appointment->status;

            $appointment->update([
                'status' => AppointmentStatus::Cancelled,
                'cancellation_reason' => $reason,
                'cancelled_at' => now(),
            ]);

            $this->appointmentService->recordPublicHistory(
                $company,
                $appointment,
                AppointmentHistoryType::Cancelled,
                [
                    'old_status' => $oldStatus,
                    'new_status' => $appointment->status,
                    'old_start_at' => $appointment->start_at,
                    'new_start_at' => $appointment->start_at,
                    'old_end_at' => $appointment->end_at,
                    'new_end_at' => $appointment->end_at,
                    'description' => $reason,
                ],
                ['source' => 'public'],
            );

            DB::afterCommit(fn () => event(new AppointmentCancelled(
                $appointment->refresh(),
                CarbonImmutable::parse($appointment->start_at),
                'public',
            )));

            return $appointment->refresh();
        });
    }

    public function reschedulePublic(
        Company $company,
        Appointment $appointment,
        CarbonImmutable $localStart,
    ): Appointment {
        $settings = $this->settingsService->getOrCreate($company);

        $this->assertPublicAppointment($company, $appointment);
        $this->assertRescheduleAllowed($company, $settings, $appointment);

        return DB::transaction(function () use ($company, $appointment, $localStart): Appointment {
            $this->appointmentService->ensureBelongsToCompany($company, $appointment->refresh());

            if (! $appointment->canBeRescheduled()) {
                throw ValidationException::withMessages([
                    'start_at' => 'Este agendamento não pode ser remarcado.',
                ]);
            }

            $professional = $appointment->professional()->lockForUpdate()->firstOrFail();
            $service = $appointment->service;

            if ($service === null || ! $service->is_active || ! $service->is_online_booking_enabled) {
                throw ValidationException::withMessages([
                    'service_id' => 'Serviço indisponível para remarcação.',
                ]);
            }

            $oldStart = CarbonImmutable::parse($appointment->start_at);
            $oldEnd = CarbonImmutable::parse($appointment->end_at);
            $startUtc = CompanyDateTime::localToUtc($company, $localStart);
            $endUtc = $startUtc->addMinutes($appointment->duration_minutes_snapshot);

            $this->availabilityService->assertAvailable(
                $company,
                $professional,
                $service,
                $localStart,
                $appointment->duration_minutes_snapshot,
                $appointment->buffer_before_minutes_snapshot,
                $appointment->buffer_after_minutes_snapshot,
                $appointment,
            )->assertAvailable();

            $appointment->update([
                'start_at' => $startUtc,
                'end_at' => $endUtc,
            ]);

            $this->appointmentService->recordPublicHistory(
                $company,
                $appointment,
                AppointmentHistoryType::Rescheduled,
                [
                    'old_status' => $appointment->status,
                    'new_status' => $appointment->status,
                    'old_start_at' => $oldStart,
                    'new_start_at' => $appointment->start_at,
                    'old_end_at' => $oldEnd,
                    'new_end_at' => $appointment->end_at,
                ],
                ['source' => 'public'],
            );

            $activeToken = $appointment->publicAccessTokens()->active()->first();

            if ($activeToken !== null) {
                $this->tokenService->refreshExpiration($activeToken);
            }

            DB::afterCommit(fn () => event(new AppointmentRescheduled(
                $appointment->refresh(),
                $oldStart,
                $oldEnd,
                'public',
            )));

            return $appointment->refresh();
        });
    }

    protected function assertPublicAppointment(Company $company, Appointment $appointment): void
    {
        $this->appointmentService->ensureBelongsToCompany($company, $appointment);

        if ($appointment->origin !== AppointmentOrigin::Online) {
            throw ValidationException::withMessages([
                'appointment' => 'Agendamento inválido.',
            ]);
        }
    }

    protected function assertCancellationAllowed(
        Company $company,
        CompanySchedulingSetting $settings,
        Appointment $appointment,
    ): void {
        if (! (bool) $settings->allow_public_cancellation) {
            throw ValidationException::withMessages([
                'status' => 'Cancelamento não permitido para este agendamento.',
            ]);
        }

        $localStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);
        $now = CompanyDateTime::nowLocal($company);

        if ($localStart->lte($now)) {
            throw ValidationException::withMessages([
                'status' => 'Este agendamento não pode ser cancelado.',
            ]);
        }

        $minimumStart = $now->addMinutes((int) $settings->cancellation_minimum_advance_minutes);

        if ($localStart->lt($minimumStart)) {
            throw ValidationException::withMessages([
                'status' => 'O prazo para cancelamento expirou.',
            ]);
        }
    }

    protected function assertRescheduleAllowed(
        Company $company,
        CompanySchedulingSetting $settings,
        Appointment $appointment,
    ): void {
        if (! (bool) $settings->allow_public_reschedule) {
            throw ValidationException::withMessages([
                'start_at' => 'Remarcação não permitida para este agendamento.',
            ]);
        }

        $localStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);
        $now = CompanyDateTime::nowLocal($company);

        if ($localStart->lte($now)) {
            throw ValidationException::withMessages([
                'start_at' => 'Este agendamento não pode ser remarcado.',
            ]);
        }

        $minimumStart = $now->addMinutes((int) $settings->reschedule_minimum_advance_minutes);

        if ($localStart->lt($minimumStart)) {
            throw ValidationException::withMessages([
                'start_at' => 'O prazo para remarcação expirou.',
            ]);
        }
    }
}
