<?php

namespace App\Jobs;

use App\Mail\AppointmentChangeMail;
use App\Models\Appointment;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Services\WhatsApp\WhatsAppConfirmationMessageBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendAppointmentCreatedEmailJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 55;

    public int $tries = 3;

    public function __construct(
        public int $appointmentId,
        public ?string $manageUrl = null,
    ) {}

    public function handle(
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
        $clientEmail = (string) ($appointment->client_email_snapshot ?: $appointment->client?->email ?: '');

        if (filled($clientEmail)) {
            $this->sendMail(
                $clientEmail,
                "Agendamento registrado - {$company->name}",
                $messageBuilder->build($company, $appointment, $this->manageUrl),
                $appointment,
            );
        }

        foreach ($recipients->administrativeUsers($appointment) as $user) {
            if (! filled($user->email)) {
                continue;
            }

            $this->sendMail(
                (string) $user->email,
                "Novo agendamento online - {$company->name}",
                $messageBuilder->buildForStaff($company, $appointment, $this->manageUrl),
                $appointment,
            );
        }
    }

    protected function sendMail(string $to, string $subject, string $body, Appointment $appointment): void
    {
        try {
            Mail::to($to)->send(new AppointmentChangeMail($subject, $body));
        } catch (Throwable $exception) {
            Log::warning('Appointment created email notification failed.', [
                'appointment_id' => $appointment->getKey(),
                'recipient' => $to,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
