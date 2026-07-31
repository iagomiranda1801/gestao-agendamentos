<?php

namespace App\Listeners;

use App\Events\OnlineAppointmentCreated;
use App\Jobs\NotifyStaffOfOnlineBookingJob;
use App\Jobs\SendAppointmentCreatedEmailJob;
use App\Jobs\SendWhatsAppAppointmentConfirmationJob;
use App\Jobs\SendWhatsAppStaffBookingAlertJob;

class SendOnlineBookingWhatsAppNotification
{
    public function handle(OnlineAppointmentCreated $event): void
    {
        $appointmentId = $event->appointment->getKey();

        SendWhatsAppAppointmentConfirmationJob::dispatch(
            $appointmentId,
            $event->manageUrl,
        );

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
