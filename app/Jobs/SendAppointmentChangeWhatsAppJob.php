<?php

namespace App\Jobs;

use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\Appointment;
use App\Models\Company;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendAppointmentChangeWhatsAppJob implements ShouldBeUnique, ShouldQueue
{
    use DefersViaWhatsAppOutboundGate;
    use Queueable;

    public int $uniqueFor = 3600;

    public function __construct(
        public int $appointmentId,
        public string $changeType,
        public ?string $oldStartAt = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->appointmentId.':'.$this->changeType;
    }

    public function handle(
        EvolutionApiClient $client,
        CompanySchedulingSettingService $settings,
        CompanyWhatsAppInstanceService $instances,
        WhatsAppConfirmationMessageBuilder $messageBuilder,
        AppointmentNotificationRecipientService $recipients,
    ): void {
        $appointment = Appointment::query()
            ->with(['company.schedulingSetting', 'client', 'professional.user'])
            ->find($this->appointmentId);

        if ($appointment === null || $appointment->company === null) {
            return;
        }

        $company = $appointment->company;
        $companySetting = $settings->getOrCreate($company);

        if (! (bool) $companySetting->whatsapp_notifications_enabled) {
            Log::info('Appointment WhatsApp change notification skipped.', [
                'reason' => 'disabled',
                'appointment_id' => $appointment->getKey(),
                'change_type' => $this->changeType,
            ]);

            return;
        }

        $instance = $instances->resolvedNameForCompany($company);

        if ($instance === '') {
            Log::info('Appointment WhatsApp change notification skipped.', [
                'reason' => 'no_instance',
                'appointment_id' => $appointment->getKey(),
                'change_type' => $this->changeType,
            ]);

            return;
        }

        $oldStartAt = filled($this->oldStartAt) ? CarbonImmutable::parse($this->oldStartAt) : null;
        $clientMessage = $this->clientMessage($messageBuilder, $company, $appointment, $oldStartAt);
        $staffMessage = $this->staffMessage($messageBuilder, $company, $appointment, $oldStartAt);

        $clientPhone = (string) ($appointment->client_phone_snapshot ?: $appointment->client?->phone ?: '');
        $staffPhones = $recipients->staffPhones($appointment, $companySetting->whatsapp_sender_phone);

        if ($clientPhone === '' && $staffPhones === []) {
            Log::info('Appointment WhatsApp change notification skipped.', [
                'reason' => 'no_phone',
                'appointment_id' => $appointment->getKey(),
                'change_type' => $this->changeType,
            ]);

            return;
        }

        if (! $this->deferUntilOutboundSlot($company, WhatsAppOutboundKind::Confirmation)) {
            Log::info('Appointment WhatsApp change notification waiting for outbound slot.', [
                'reason' => 'deferred',
                'appointment_id' => $appointment->getKey(),
                'change_type' => $this->changeType,
                'retry_in_seconds' => $this->whatsappOutboundRetrySeconds,
            ]);

            return;
        }

        if (filled($clientPhone)) {
            $this->send($client, $instance, $clientPhone, $clientMessage, 'client', $appointment);
        }

        foreach ($staffPhones as $label => $phone) {
            $this->send($client, $instance, $phone, $staffMessage, $label, $appointment);
        }
    }

    protected function clientMessage(
        WhatsAppConfirmationMessageBuilder $messageBuilder,
        Company $company,
        Appointment $appointment,
        ?CarbonImmutable $oldStartAt,
    ): string {
        return $this->changeType === 'cancelled'
            ? $messageBuilder->buildCancellation($company, $appointment)
            : $messageBuilder->buildReschedule($company, $appointment, $oldStartAt);
    }

    protected function staffMessage(
        WhatsAppConfirmationMessageBuilder $messageBuilder,
        Company $company,
        Appointment $appointment,
        ?CarbonImmutable $oldStartAt,
    ): string {
        return $this->changeType === 'cancelled'
            ? $messageBuilder->buildCancellationForStaff($company, $appointment)
            : $messageBuilder->buildRescheduleForStaff($company, $appointment, $oldStartAt);
    }

    protected function send(
        EvolutionApiClient $client,
        string $instance,
        string $phone,
        string $message,
        string $recipient,
        Appointment $appointment,
    ): void {
        try {
            $client->sendText($instance, $phone, $message);
            $this->rememberOutboundSuccess($appointment->company);
        } catch (Throwable $exception) {
            Log::warning('Appointment WhatsApp change notification failed.', [
                'appointment_id' => $appointment->getKey(),
                'change_type' => $this->changeType,
                'recipient' => $recipient,
                'error' => $exception->getMessage(),
            ]);
            $this->rememberOutboundFailureAndMaybeRethrow($appointment->company, $exception);
        }
    }
}
