<?php

namespace App\Listeners;

use App\Events\OnlineAppointmentCreated;
use App\Jobs\NotifyStaffOfOnlineBookingJob;
use App\Jobs\SendWhatsAppAppointmentConfirmationJob;
use App\Jobs\SendWhatsAppStaffBookingAlertJob;

class SendOnlineBookingWhatsAppNotification
{
    public function handle(OnlineAppointmentCreated $event): void
    {
        $appointmentId = $event->appointment->getKey();

        SendWhatsAppAppointmentConfirmationJob::dispatchAfterResponse(
            $appointmentId,
            $event->manageUrl,
        );

        SendWhatsAppStaffBookingAlertJob::dispatchAfterResponse(
            $appointmentId,
            $event->manageUrl,
        );

        NotifyStaffOfOnlineBookingJob::dispatch($appointmentId);
    }
}
