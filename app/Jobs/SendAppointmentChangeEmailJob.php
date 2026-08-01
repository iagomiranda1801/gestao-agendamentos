<?php

namespace App\Jobs;

use App\Mail\AppointmentChangeMail;
use App\Models\Appointment;
use App\Models\Company;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAppointmentChangeEmailJob implements ShouldQueue
{
    use Queueable;

    /** Keep a stalled SMTP connection from occupying a worker indefinitely. */
    public int $timeout = 55;

    public int $tries = 3;

    public function __construct(
        public int $appointmentId,
        public string $changeType,
        public ?string $oldStartAt = null,
    ) {}

    public function handle(
        WhatsAppConfirmationMessageBuilder $messageBuilder,
        AppointmentNotificationRecipientService $recipients,
    ): void {
        $appointment = Appointment::query()
            ->with(['company', 'client', 'professional.user'])
            ->find($this->appointmentId);

        if ($appointment === null || $appointment->company === null) {
            return;
        }

        $company = $appointment->company;
        $oldStartAt = filled($this->oldStartAt) ? CarbonImmutable::parse($this->oldStartAt) : null;

        $clientEmail = (string) ($appointment->client_email_snapshot ?: $appointment->client?->email ?: '');

        if (filled($clientEmail)) {
            $this->sendMail(
                $clientEmail,
                $this->subject($company, false),
                $this->clientMessage($messageBuilder, $company, $appointment, $oldStartAt),
                $appointment,
            );
        }

        foreach ($recipients->staffUsers($appointment) as $user) {
            if (! filled($user->email)) {
                continue;
            }

            $this->sendMail(
                (string) $user->email,
                $this->subject($company, true),
                $this->staffMessage($messageBuilder, $company, $appointment, $oldStartAt),
                $appointment,
            );
        }
    }

    protected function subject(Company $company, bool $staff): string
    {
        $action = $this->changeType === 'cancelled' ? 'cancelado' : 'remarcado';
        $prefix = $staff ? 'Agendamento' : 'Seu agendamento';

        return "{$prefix} {$action} - {$company->name}";
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

    protected function sendMail(string $to, string $subject, string $body, Appointment $appointment): void
    {
        try {
            Mail::to($to)->send(new AppointmentChangeMail($subject, $body));
        } catch (Throwable $exception) {
            Log::warning('Appointment email change notification failed.', [
                'appointment_id' => $appointment->getKey(),
                'change_type' => $this->changeType,
                'recipient' => $to,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
