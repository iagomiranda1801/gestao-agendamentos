<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendProfessionalAppointmentWhatsAppJob implements ShouldBeUnique, ShouldQueue
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
        EvolutionApiClient $client,
        WhatsAppConfirmationMessageBuilder $messageBuilder,
        AppointmentNotificationRecipientService $recipients,
    ): void {
        $appointment = Appointment::query()
            ->with(['company.schedulingSetting', 'client', 'professional.user'])
            ->find($this->appointmentId);

        if ($appointment === null || $appointment->company === null) {
            return;
        }

        $settings = $appointment->company->schedulingSetting;

        if (! (bool) ($settings?->whatsapp_notifications_enabled ?? false)
            || ! (bool) ($settings?->notify_professional_by_whatsapp ?? true)) {
            return;
        }

        $instance = $client->resolveInstance($settings?->whatsapp_instance);
        $phone = $recipients->professionalPhone($appointment);

        if ($instance === '' || $phone === null) {
            Log::info('Professional appointment WhatsApp skipped: missing configuration or recipient.', [
                'appointment_id' => $appointment->getKey(),
                'notification_type' => $this->notificationType,
            ]);

            return;
        }

        $oldStartAt = filled($this->oldStartAt) ? CarbonImmutable::parse($this->oldStartAt) : null;
        $message = $messageBuilder->buildForProfessional(
            $appointment->company,
            $appointment,
            $this->notificationType,
            $oldStartAt,
        );

        try {
            $client->sendText($instance, $phone, $message);
        } catch (Throwable $exception) {
            Log::warning('Professional appointment WhatsApp failed.', [
                'appointment_id' => $appointment->getKey(),
                'notification_type' => $this->notificationType,
                'error' => $exception->getMessage(),
            ]);

            // The sync driver is used in local/tests and must not turn an already
            // committed appointment into an error response. Real queue workers
            // rethrow so Laravel can apply the configured retries/backoff.
            if (config('queue.default') !== 'sync') {
                throw $exception;
            }
        }
    }
}
