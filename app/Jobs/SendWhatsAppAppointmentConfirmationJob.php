<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppAppointmentConfirmationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $appointmentId,
        public ?string $manageUrl = null,
    ) {}

    public function handle(
        EvolutionApiClient $client,
        WhatsAppConfirmationMessageBuilder $messageBuilder,
    ): void {
        $appointment = Appointment::query()
            ->with(['company.schedulingSetting', 'client'])
            ->find($this->appointmentId);

        if ($appointment === null) {
            return;
        }

        $company = $appointment->company;
        $settings = $company?->schedulingSetting;

        if ($company === null || ! (bool) ($settings?->whatsapp_notifications_enabled ?? false)) {
            return;
        }

        $instance = $client->resolveInstance($settings->whatsapp_instance ?? null);

        if ($instance === '') {
            Log::warning('WhatsApp confirmation skipped: missing company instance.', [
                'appointment_id' => $appointment->getKey(),
                'company_id' => $company->getKey(),
            ]);

            return;
        }

        $phone = (string) ($appointment->client_phone_snapshot ?? '');

        if ($phone === '') {
            Log::warning('WhatsApp confirmation skipped: missing phone snapshot.', [
                'appointment_id' => $appointment->getKey(),
            ]);

            return;
        }

        $message = $messageBuilder->build($company, $appointment, $this->manageUrl);

        try {
            $client->sendText($instance, $phone, $message);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp confirmation failed.', [
                'appointment_id' => $appointment->getKey(),
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
