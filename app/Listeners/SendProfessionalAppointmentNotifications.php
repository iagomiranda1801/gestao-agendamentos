<?php

namespace App\Listeners;

use App\Events\AppointmentCancelled;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentCreated;
use App\Events\AppointmentRescheduled;
use App\Events\OnlineAppointmentCreated;
use App\Jobs\SendProfessionalAppointmentEmailJob;
use App\Jobs\SendProfessionalAppointmentWhatsAppJob;

class SendProfessionalAppointmentNotifications
{
    public function handle(
        AppointmentCreated|OnlineAppointmentCreated|AppointmentConfirmed|AppointmentRescheduled|AppointmentCancelled $event,
    ): void {
        $type = match (true) {
            $event instanceof AppointmentConfirmed => 'confirmed',
            $event instanceof AppointmentRescheduled => 'rescheduled',
            $event instanceof AppointmentCancelled => 'cancelled',
            default => 'created',
        };

        $oldStartAt = $event instanceof AppointmentRescheduled || $event instanceof AppointmentCancelled
            ? $event->oldStartAt?->toIso8601String()
            : null;

        SendProfessionalAppointmentEmailJob::dispatch(
            $event->appointment->getKey(),
            $type,
            $oldStartAt,
        );

        SendProfessionalAppointmentWhatsAppJob::dispatch(
            $event->appointment->getKey(),
            $type,
            $oldStartAt,
        );
    }
}
