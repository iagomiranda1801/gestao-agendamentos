<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use App\Support\PhoneNormalizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppStaffBookingAlertJob implements ShouldQueue
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
            ->with(['company.schedulingSetting', 'professional'])
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
            Log::warning('WhatsApp staff alert skipped: missing company instance.', [
                'appointment_id' => $appointment->getKey(),
                'company_id' => $company->getKey(),
            ]);

            return;
        }

        $message = $messageBuilder->buildForStaff($company, $appointment, $this->manageUrl);
        $recipients = $this->recipientPhones($appointment, $settings?->whatsapp_sender_phone);

        if ($recipients === []) {
            Log::warning('WhatsApp staff alert skipped: no company/professional phones.', [
                'appointment_id' => $appointment->getKey(),
                'company_id' => $company->getKey(),
            ]);

            return;
        }

        foreach ($recipients as $label => $phone) {
            try {
                $client->sendText($instance, $phone, $message);
            } catch (Throwable $exception) {
                Log::warning('WhatsApp staff alert failed.', [
                    'appointment_id' => $appointment->getKey(),
                    'recipient' => $label,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function recipientPhones(Appointment $appointment, ?string $senderPhoneFallback = null): array
    {
        $companyPhone = PhoneNormalizer::normalize($appointment->company?->phone)
            ?? PhoneNormalizer::normalize($senderPhoneFallback);
        $professionalPhone = PhoneNormalizer::normalize($appointment->professional?->phone);

        $candidates = [
            'company' => $companyPhone === $professionalPhone ? null : $companyPhone,
        ];

        $seen = [];
        $recipients = [];

        foreach ($candidates as $label => $phone) {
            if (! filled($phone) || isset($seen[$phone])) {
                continue;
            }

            $seen[$phone] = true;
            $recipients[$label] = $phone;
        }

        return $recipients;
    }
}
