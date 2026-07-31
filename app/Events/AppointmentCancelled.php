<?php

namespace App\Events;

use App\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AppointmentCancelled
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public ?CarbonImmutable $oldStartAt = null,
        public string $source = 'internal',
    ) {}
}
