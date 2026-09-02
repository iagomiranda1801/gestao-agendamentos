<?php

namespace App\Jobs;

use App\Enums\WhatsAppOutboundKind;
use App\Jobs\Concerns\DefersViaWhatsAppOutboundGate;
use App\Models\Appointment;
use App\Services\PublicBooking\PublicAppointmentTokenService;
use App\Services\PublicBooking\PublicConfirmationCodeGenerator;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendWhatsAppAppointmentConfirmationJob implements ShouldQueue
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
        ?PublicAppointmentTokenService $tokens = null,
        ?PublicConfirmationCodeGenerator $codes = null,
    ): void {
        $appointment = Appointment::query()
            ->with(['company.schedulingSetting', 'client', 'professional'])
            ->find($this->appointmentId);

        if ($appointment === null) {
            return;
        }

        $company = $appointment->company;
        $settings = $company?->schedulingSetting;

        if ($company === null || ! (bool) ($settings?->whatsapp_notifications_enabled ?? false)) {
            Log::info('WhatsApp confirmation skipped.', [
                'reason' => 'disabled',
                'appointment_id' => $appointment->getKey(),
            ]);

            return;
        }

        $instance = $instances->resolvedNameForCompany($company);

        if ($instance === '') {
            Log::info('WhatsApp confirmation skipped.', [
                'reason' => 'no_instance',
                'appointment_id' => $appointment->getKey(),
                'company_id' => $company->getKey(),
            ]);

            return;
        }

        $phone = (string) ($appointment->client_phone_snapshot ?: $appointment->client?->phone ?: '');

        if ($phone === '') {
            Log::info('WhatsApp confirmation skipped.', [
                'reason' => 'no_phone',
                'appointment_id' => $appointment->getKey(),
            ]);

            return;
        }

        if (! $this->deferUntilOutboundSlot($company, WhatsAppOutboundKind::Confirmation)) {
            Log::info('WhatsApp confirmation skipped.', [
                'reason' => 'deferred',
                'appointment_id' => $appointment->getKey(),
            ]);

            return;
        }

        $codes ??= app(PublicConfirmationCodeGenerator::class);
        $tokens ??= app(PublicAppointmentTokenService::class);
        $codes->ensureForOnlineAppointment($appointment);

        $message = $messageBuilder->build(
            $company,
            $appointment,
            $tokens->resolveManageUrl($appointment, $this->manageUrl),
        );

        try {
            $client->sendText($instance, $phone, $message);
            $this->rememberOutboundSuccess($company);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp confirmation failed.', [
                'appointment_id' => $appointment->getKey(),
                'error' => $exception->getMessage(),
            ]);
            $this->rememberOutboundFailureAndMaybeRethrow($company, $exception);
        }
    }
}
