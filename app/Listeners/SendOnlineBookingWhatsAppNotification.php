<?php

namespace App\Listeners;

use App\Events\AppointmentCreated;
use App\Events\OnlineAppointmentCreated;
use App\Jobs\NotifyStaffOfOnlineBookingJob;
use App\Jobs\SendAppointmentCreatedEmailJob;
use App\Jobs\SendWhatsAppAppointmentConfirmationJob;
use App\Jobs\SendWhatsAppStaffBookingAlertJob;

class SendOnlineBookingWhatsAppNotification
{
    public function handle(OnlineAppointmentCreated|AppointmentCreated $event): void
    {
        $appointmentId = $event->appointment->getKey();

        if ($event->appointment->send_whatsapp_confirmation) {
            SendWhatsAppAppointmentConfirmationJob::dispatch(
                $appointmentId,
                $event->manageUrl,
            );
        }

        SendWhatsAppStaffBookingAlertJob::dispatch(
            $appointmentId,
            $event->manageUrl,
        );

        SendAppointmentCreatedEmailJob::dispatch(
            $appointmentId,
            $event->manageUrl,
        );

        NotifyStaffOfOnlineBookingJob::dispatch($appointmentId);
    }
}
