<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\Company;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendAppointmentChangeWhatsAppJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $appointmentId,
        public string $changeType,
        public ?string $oldStartAt = null,
    ) {}

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
            return;
        }

        $defaultInstance = $instances->defaultForCompany($company);
        $instance = $client->resolveInstance($defaultInstance?->instance_name ?: $companySetting->whatsapp_instance);

        if ($instance === '') {
            Log::warning('Appointment WhatsApp change notification skipped: missing instance.', [
                'appointment_id' => $appointment->getKey(),
                'change_type' => $this->changeType,
            ]);

            return;
        }

        $oldStartAt = filled($this->oldStartAt) ? CarbonImmutable::parse($this->oldStartAt) : null;
        $clientMessage = $this->clientMessage($messageBuilder, $company, $appointment, $oldStartAt);
        $staffMessage = $this->staffMessage($messageBuilder, $company, $appointment, $oldStartAt);

        $clientPhone = (string) ($appointment->client_phone_snapshot ?: $appointment->client?->phone ?: '');

        if (filled($clientPhone)) {
            $this->send($client, $instance, $clientPhone, $clientMessage, 'client', $appointment);
        }

        foreach ($recipients->staffPhones($appointment, $companySetting->whatsapp_sender_phone) as $label => $phone) {
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
        } catch (Throwable $exception) {
            Log::warning('Appointment WhatsApp change notification failed.', [
                'appointment_id' => $appointment->getKey(),
                'change_type' => $this->changeType,
                'recipient' => $recipient,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
