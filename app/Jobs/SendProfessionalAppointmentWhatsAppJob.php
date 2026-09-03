<?php

namespace App\Jobs;

use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\Appointment;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
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
    use DefersViaWhatsAppOutboundGate;
    use Queueable;

    public int $timeout = 55;

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
        CompanyWhatsAppInstanceService $instances,
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
            Log::info('Professional appointment WhatsApp skipped.', [
                'reason' => 'disabled',
                'appointment_id' => $appointment->getKey(),
                'notification_type' => $this->notificationType,
            ]);

            return;
        }

        $instance = $instances->resolvedNameForCompany($appointment->company);
        $phone = $recipients->professionalPhone($appointment);

        if ($instance === '') {
            Log::info('Professional appointment WhatsApp skipped.', [
                'reason' => 'no_instance',
                'appointment_id' => $appointment->getKey(),
                'notification_type' => $this->notificationType,
            ]);

            return;
        }

        if ($phone === null) {
            Log::info('Professional appointment WhatsApp skipped.', [
                'reason' => 'no_phone',
                'appointment_id' => $appointment->getKey(),
                'notification_type' => $this->notificationType,
            ]);

            return;
        }

        if (! $this->deferUntilOutboundSlot($appointment->company, WhatsAppOutboundKind::Confirmation)) {
            Log::info('Professional appointment WhatsApp waiting for outbound slot.', [
                'reason' => 'deferred',
                'appointment_id' => $appointment->getKey(),
                'notification_type' => $this->notificationType,
                'retry_in_seconds' => $this->whatsappOutboundRetrySeconds,
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
            $this->rememberOutboundSuccess($appointment->company);
        } catch (Throwable $exception) {
            Log::warning('Professional appointment WhatsApp failed.', [
                'appointment_id' => $appointment->getKey(),
                'notification_type' => $this->notificationType,
                'error' => $exception->getMessage(),
            ]);
            $this->rememberOutboundFailureAndMaybeRethrow($appointment->company, $exception);
        }
    }
}
