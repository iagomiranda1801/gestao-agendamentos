<?php

namespace App\Livewire\PublicBooking;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentPublicAccessToken;
use App\Models\Company;
use App\Services\PublicBooking\OnlineBookingCatalogService;
use App\Services\PublicBooking\PublicAppointmentService;
use App\Services\PublicBooking\PublicAppointmentTokenService;
use App\Services\PublicBooking\PublicBookingRateLimiter;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Throwable;

#[Layout('layouts.public-booking')]
class ManageAppointment extends Component
{
    public Appointment $appointment;

    public Company $company;

    public AppointmentPublicAccessToken $accessToken;

    public bool $showCancelModal = false;

    public bool $showRescheduleModal = false;

    public string $cancelReason = '';

    public ?string $rescheduleDate = null;

    public ?string $rescheduleTime = null;

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public bool $isSubmitting = false;

    public function mount(
        string $token,
        PublicAppointmentTokenService $tokenService,
        PublicBookingRateLimiter $rateLimiter,
    ): void {
        try {
            $rateLimiter->assertManageViewAllowed((string) request()->ip());
        } catch (ValidationException $exception) {
            abort(429, collect($exception->errors())->flatten()->first());
        }

        $accessToken = $tokenService->resolve($token);

        abort_unless($accessToken !== null, 404);

        $this->accessToken = $accessToken;
        $this->appointment = $accessToken->appointment()
            ->with(['company.schedulingSetting'])
            ->firstOrFail();
        $this->company = $this->appointment->company;
    }

    /**
     * @return array<string, mixed>
     */
    protected function bookingLayoutData(): array
    {
        $settings = $this->company->schedulingSetting;

        return [
            'primaryColor' => filled($settings?->booking_primary_color)
                ? $settings->booking_primary_color
                : '#2563eb',
            'company' => $this->company,
        ];
    }

    public function openCancelModal(): void
    {
        if (! $this->canManage()) {
            return;
        }

        $this->showCancelModal = true;
        $this->cancelReason = '';
        $this->errorMessage = null;
    }

    public function closeCancelModal(): void
    {
        $this->showCancelModal = false;
    }

    public function openRescheduleModal(): void
    {
        if (! $this->canReschedule()) {
            return;
        }

        $this->showRescheduleModal = true;
        $this->rescheduleDate = null;
        $this->rescheduleTime = null;
        $this->errorMessage = null;
    }

    public function closeRescheduleModal(): void
    {
        $this->showRescheduleModal = false;
    }

    public function selectRescheduleTime(string $time): void
    {
        $this->rescheduleTime = $time;
    }

    public function submitCancel(
        PublicAppointmentService $appointmentService,
        PublicBookingRateLimiter $rateLimiter,
    ): void {
        if (! $this->canManage()) {
            return;
        }

        $this->errorMessage = null;
        $this->successMessage = null;
        $this->isSubmitting = true;

        try {
            $this->validate([
                'cancelReason' => ['required', 'string', 'min:3', 'max:500'],
            ], [
                'cancelReason.required' => 'Informe o motivo do cancelamento.',
                'cancelReason.min' => 'Descreva o motivo com pelo menos 3 caracteres.',
            ]);

            $rateLimiter->assertManageActionAllowed(
                $this->accessToken->token_hash,
                (string) request()->ip(),
            );

            $this->appointment = $appointmentService->cancelPublic(
                $this->company,
                $this->appointment,
                $this->cancelReason,
            );

            $this->showCancelModal = false;
            $this->successMessage = 'Agendamento cancelado com sucesso.';
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->errorMessage = $exception->getMessage() ?: 'Não foi possível cancelar o agendamento.';
        } finally {
            $this->isSubmitting = false;
        }
    }

    public function submitReschedule(
        PublicAppointmentService $appointmentService,
        PublicBookingRateLimiter $rateLimiter,
    ): void {
        if (! $this->canReschedule()) {
            return;
        }

        $this->errorMessage = null;
        $this->successMessage = null;
        $this->isSubmitting = true;

        try {
            $this->validate([
                'rescheduleDate' => ['required', 'date'],
                'rescheduleTime' => ['required', 'date_format:H:i'],
            ], [
                'rescheduleDate.required' => 'Selecione uma data.',
                'rescheduleTime.required' => 'Selecione um horário.',
            ]);

            $rateLimiter->assertManageActionAllowed(
                $this->accessToken->token_hash,
                (string) request()->ip(),
            );

            $localStart = CompanyDateTime::parseLocal(
                $this->company,
                (string) $this->rescheduleDate,
                (string) $this->rescheduleTime,
            );

            $this->appointment = $appointmentService->reschedulePublic(
                $this->company,
                $this->appointment,
                $localStart,
            );

            $this->showRescheduleModal = false;
            $this->successMessage = 'Agendamento reagendado com sucesso.';
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->errorMessage = $exception->getMessage() ?: 'Não foi possível reagendar.';
        } finally {
            $this->isSubmitting = false;
        }
    }

    public function render(OnlineBookingCatalogService $catalog)
    {
        $settings = $this->company->schedulingSetting;
        $localStart = CompanyDateTime::utcToLocal($this->company, CarbonImmutable::parse($this->appointment->start_at));

        $rescheduleDates = collect();
        $rescheduleSlots = collect();

        if ($this->showRescheduleModal && $this->canReschedule()) {
            $service = $this->appointment->service;

            if ($service !== null) {
                $rescheduleDates = $catalog->getAvailableDates(
                    $this->company,
                    $service,
                    $this->appointment->professional_id,
                );

                if ($this->rescheduleDate !== null) {
                    $localDate = CarbonImmutable::parse(
                        $this->rescheduleDate,
                        CompanyDateTime::timezone($this->company),
                    );

                    $rescheduleSlots = $catalog->getAvailableSlots(
                        $this->company,
                        $service,
                        $this->appointment->professional_id,
                        $localDate,
                    );
                }
            }
        }

        return view('livewire.public-booking.manage-appointment', [
            'settings' => $settings,
            'localStart' => $localStart,
            'localEnd' => CompanyDateTime::utcToLocal($this->company, CarbonImmutable::parse($this->appointment->end_at)),
            'canCancel' => $this->canCancel(),
            'canReschedule' => $this->canReschedule(),
            'cancelUnavailableReason' => $this->cancelUnavailableReason(),
            'rescheduleUnavailableReason' => $this->rescheduleUnavailableReason(),
            'isViewOnly' => $this->isViewOnly(),
            'rescheduleDates' => $rescheduleDates,
            'rescheduleSlots' => $rescheduleSlots,
        ])->layoutData($this->bookingLayoutData());
    }

    public function canManage(): bool
    {
        return ! $this->isViewOnly();
    }

    public function isViewOnly(): bool
    {
        return $this->appointment->isCancelled();
    }

    public function canCancel(): bool
    {
        return $this->cancelUnavailableReason() === null;
    }

    public function cancelUnavailableReason(): ?string
    {
        if ($this->isViewOnly()) {
            return 'Este agendamento já foi cancelado.';
        }

        $settings = $this->company->schedulingSetting;

        if (! $settings?->allow_public_cancellation) {
            return 'O cancelamento online não está habilitado para este agendamento.';
        }

        if (! $this->appointment->canBeCancelled()) {
            return 'Este agendamento não está mais disponível para cancelamento.';
        }

        if (CarbonImmutable::parse($this->appointment->start_at)->lte(now())) {
            return 'Este agendamento já iniciou ou já passou.';
        }

        $minimumMinutes = (int) ($settings->cancellation_minimum_advance_minutes ?? 0);

        if ($minimumMinutes <= 0) {
            return null;
        }

        $deadline = CarbonImmutable::parse($this->appointment->start_at)->subMinutes($minimumMinutes);

        if (now()->gt($deadline)) {
            return 'O prazo para cancelamento online expirou.';
        }

        return null;
    }

    public function canReschedule(): bool
    {
        return $this->rescheduleUnavailableReason() === null;
    }

    public function rescheduleUnavailableReason(): ?string
    {
        if ($this->isViewOnly()) {
            return 'Este agendamento já foi cancelado.';
        }

        $settings = $this->company->schedulingSetting;

        if (! $settings?->allow_public_reschedule) {
            return 'A remarcação online não está habilitada para este agendamento.';
        }

        if (! $this->appointment->canBeRescheduled()) {
            return 'Este agendamento não está mais disponível para remarcação.';
        }

        if (CarbonImmutable::parse($this->appointment->start_at)->lte(now())) {
            return 'Este agendamento já iniciou ou já passou.';
        }

        $minimumMinutes = (int) ($settings->reschedule_minimum_advance_minutes ?? 0);

        if ($minimumMinutes <= 0) {
            return null;
        }

        $deadline = CarbonImmutable::parse($this->appointment->start_at)->subMinutes($minimumMinutes);

        if (now()->gt($deadline)) {
            return 'O prazo para remarcação online expirou.';
        }

        return null;
    }

    public function statusBadgeClass(): string
    {
        return match ($this->appointment->status) {
            AppointmentStatus::Pending => 'booking-status-badge--pending',
            AppointmentStatus::Confirmed => 'booking-status-badge--confirmed',
            AppointmentStatus::Cancelled => 'booking-status-badge--cancelled',
            default => 'booking-status-badge--pending',
        };
    }

    public function formatMoney(?string $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return 'R$ '.number_format((float) $amount, 2, ',', '.');
    }

    /**
     * @param  Collection<int, CarbonImmutable>  $dates
     */
    public function weekdayLabel(CarbonImmutable $date): string
    {
        $labels = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];

        return $labels[$date->dayOfWeek] ?? $date->format('D');
    }
}
