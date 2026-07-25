<?php

namespace App\DataTransferObjects\PublicBooking;

use App\Models\Appointment;

readonly class OnlineBookingResult
{
    public function __construct(
        public Appointment $appointment,
        public ?string $plainToken,
        public string $confirmationCode,
        public ?string $manageUrl,
        public bool $whatsappQueued = false,
    ) {}
}
