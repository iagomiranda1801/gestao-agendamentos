<?php

namespace App\Listeners;

use App\Events\AppointmentRescheduled;
use App\Jobs\SendAppointmentChangeEmailJob;
use App\Jobs\SendAppointmentChangeWhatsAppJob;

class SendAppointmentRescheduledNotifications
{
    public function handle(AppointmentRescheduled $event): void
    {
        $appointmentId = $event->appointment->getKey();
        $oldStartAt = $event->oldStartAt?->toIso8601String();

        SendAppointmentChangeWhatsAppJob::dispatch($appointmentId, 'rescheduled', $oldStartAt);
        SendAppointmentChangeEmailJob::dispatch($appointmentId, 'rescheduled', $oldStartAt);
    }
}
