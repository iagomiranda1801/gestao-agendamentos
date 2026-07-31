<?php

namespace App\Listeners;

use App\Events\AppointmentCancelled;
use App\Jobs\SendAppointmentChangeEmailJob;
use App\Jobs\SendAppointmentChangeWhatsAppJob;

class SendAppointmentCancelledNotifications
{
    public function handle(AppointmentCancelled $event): void
    {
        $appointmentId = $event->appointment->getKey();
        $oldStartAt = $event->oldStartAt?->toIso8601String();

        SendAppointmentChangeWhatsAppJob::dispatch($appointmentId, 'cancelled', $oldStartAt);
        SendAppointmentChangeEmailJob::dispatch($appointmentId, 'cancelled', $oldStartAt);
    }
}
