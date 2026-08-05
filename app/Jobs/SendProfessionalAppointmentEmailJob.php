<?php

namespace App\Jobs;

use App\Mail\AppointmentChangeMail;
use App\Models\Appointment;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendProfessionalAppointmentEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 55;

    public int $tries = 3;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $appointmentId,
        public string $notificationType,
        public ?string $oldStartAt = null,
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->appointmentId, $this->notificationType, $this->oldStartAt ?? 'current']);
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    public function handle(
        WhatsAppConfirmationMessageBuilder $messageBuilder,
        AppointmentNotificationRecipientService $recipients,
    ): void {
        $appointment = Appointment::query()
            ->with(['company.schedulingSetting', 'client', 'professional.user'])
            ->find($this->appointmentId);

        if ($appointment === null
            || $appointment->company === null
            || ! (bool) ($appointment->company->schedulingSetting?->notify_professional_by_email ?? true)) {
            return;
        }

        $email = $recipients->professionalEmail($appointment);

        if ($email === null) {
            Log::info('Professional appointment email skipped: missing recipient.', [
                'appointment_id' => $appointment->getKey(),
                'notification_type' => $this->notificationType,
            ]);

            return;
        }

        $oldStartAt = filled($this->oldStartAt) ? CarbonImmutable::parse($this->oldStartAt) : null;
        $subject = $messageBuilder->professionalSubject(
            $appointment->company,
            $this->notificationType,
        );
        $body = $messageBuilder->buildForProfessional(
            $appointment->company,
            $appointment,
            $this->notificationType,
            $oldStartAt,
        );

        try {
            Mail::to($email)->send(new AppointmentChangeMail($subject, $body));
        } catch (Throwable $exception) {
            Log::warning('Professional appointment email failed.', [
                'appointment_id' => $appointment->getKey(),
                'notification_type' => $this->notificationType,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
