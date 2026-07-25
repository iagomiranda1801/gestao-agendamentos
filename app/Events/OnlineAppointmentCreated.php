<?php

namespace App\Events;

use App\Models\Appointment;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OnlineAppointmentCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public ?string $manageUrl = null,
    ) {}
}
