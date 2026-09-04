<?php

namespace App\Jobs;

use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\Appointment;
use App\Services\PublicBooking\PublicConfirmationCodeGenerator;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppStaffBookingAlertJob implements ShouldBeUnique, ShouldQueue
{
    use DefersViaWhatsAppOutboundGate;
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $appointmentId,
        public ?string $manageUrl = null,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->appointmentId;
    }

    public function handle(
        EvolutionApiClient $client,
        WhatsAppConfirmationMessageBuilder $messageBuilder,
        CompanyWhatsAppInstanceService $instances,
        AppointmentNotificationRecipientService $recipients,
        ?PublicConfirmationCodeGenerator $codes = null,
    ): void {
        $appointment = Appointment::query()
            ->with(['company.schedulingSetting', 'professional'])
            ->find($this->appointmentId);

        if ($appointment === null) {
            return;
        }

        if ($this->skipStaleCreation($appointment)) {
            return;
        }

        $company = $appointment->company;
        $settings = $company?->schedulingSetting;

        if ($company === null || ! (bool) ($settings?->whatsapp_notifications_enabled ?? false)) {
            Log::info('WhatsApp staff alert skipped.', [
                'reason' => 'disabled',
                'appointment_id' => $appointment->getKey(),
            ]);

            return;
        }

        $instance = $instances->resolvedNameForCompany($company);

        if ($instance === '') {
            Log::info('WhatsApp staff alert skipped.', [
                'reason' => 'no_instance',
                'appointment_id' => $appointment->getKey(),
                'company_id' => $company->getKey(),
            ]);

            return;
        }

        $phones = $recipients->staffPhones($appointment, $settings?->whatsapp_sender_phone);

        if ($phones === []) {
            Log::info('WhatsApp staff alert skipped.', [
                'reason' => 'no_phone',
                'appointment_id' => $appointment->getKey(),
                'company_id' => $company->getKey(),
            ]);

            return;
        }

        if (! $this->deferUntilOutboundSlot($company, WhatsAppOutboundKind::Confirmation)) {
            Log::info('WhatsApp staff alert waiting for outbound slot.', [
                'reason' => 'deferred',
                'appointment_id' => $appointment->getKey(),
                'retry_in_seconds' => $this->whatsappOutboundRetrySeconds,
            ]);

            return;
        }

        $appointment->refresh();

        if ($this->skipStaleCreation($appointment)) {
            return;
        }

        $codes ??= app(PublicConfirmationCodeGenerator::class);
        $codes->ensureForOnlineAppointment($appointment);

        $message = $messageBuilder->buildForStaff($company, $appointment, $this->manageUrl);

        foreach ($phones as $label => $phone) {
            try {
                $client->sendText($instance, $phone, $message);
                $this->rememberOutboundSuccess($company);
            } catch (Throwable $exception) {
                Log::warning('WhatsApp staff alert failed.', [
                    'appointment_id' => $appointment->getKey(),
                    'recipient' => $label,
                    'error' => $exception->getMessage(),
                ]);
                $this->rememberOutboundFailureAndMaybeRethrow($company, $exception);
            }
        }
    }

    protected function skipStaleCreation(Appointment $appointment): bool
    {
        if (! $appointment->isStaleForCreationWhatsApp()) {
            return false;
        }

        Log::info('WhatsApp staff alert skipped.', [
            'reason' => 'stale',
            'appointment_id' => $appointment->getKey(),
            'status' => $appointment->status->value,
        ]);

        return true;
    }
}
