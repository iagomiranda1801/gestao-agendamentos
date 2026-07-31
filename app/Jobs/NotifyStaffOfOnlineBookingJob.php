<?php

namespace App\Jobs;

use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Models\Appointment;
use App\Services\Scheduling\AppointmentNotificationRecipientService;
use App\Support\CompanyDateTime;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class NotifyStaffOfOnlineBookingJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public int $appointmentId,
    ) {}

    public function handle(): void
    {
        $appointment = Appointment::query()
            ->with(['company', 'professional.user'])
            ->find($this->appointmentId);

        if ($appointment === null || $appointment->company === null) {
            return;
        }

        $recipients = app(AppointmentNotificationRecipientService::class)->staffUsers($appointment);

        if ($recipients->isEmpty()) {
            return;
        }

        $company = $appointment->company;
        $localStart = CompanyDateTime::utcToLocal($company, $appointment->start_at);
        $clientName = (string) ($appointment->client_name_snapshot ?? 'Cliente');
        $serviceName = (string) ($appointment->service_name_snapshot ?? 'serviço');
        $when = $localStart->format('d/m/Y H:i');

        try {
            $url = AppointmentResource::getUrl(
                name: 'view',
                parameters: ['record' => $appointment],
                panel: 'app',
                tenant: $company,
            );
        } catch (Throwable) {
            $url = null;
        }

        $notification = Notification::make()
            ->title('Novo agendamento online')
            ->body("{$clientName} · {$serviceName} · {$when}")
            ->success();

        if (filled($url)) {
            $notification->actions([
                Action::make('view')
                    ->label('Ver agendamento')
                    ->url($url)
                    ->markAsRead(),
            ]);
        }

        $notification->sendToDatabase($recipients);
    }
}
