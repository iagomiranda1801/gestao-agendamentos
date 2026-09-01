<?php

namespace App\Jobs;

use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\Appointment;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppStaffBookingAlertJob implements ShouldQueue
{
    use DefersViaWhatsAppOutboundGate;
    use Queueable;

    public function __construct(
        public int $appointmentId,
        public ?string $manageUrl = null,
    ) {}

    public function handle(
        EvolutionApiClient $client,
        WhatsAppConfirmationMessageBuilder $messageBuilder,
        CompanyWhatsAppInstanceService $instances,
        AppointmentNotificationRecipientService $recipients,
    ): void {
        $appointment = Appointment::query()
            ->with(['company.schedulingSetting', 'professional'])
            ->find($this->appointmentId);

        if ($appointment === null) {
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
            Log::info('WhatsApp staff alert skipped.', [
                'reason' => 'deferred',
                'appointment_id' => $appointment->getKey(),
            ]);

            return;
        }

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
}
