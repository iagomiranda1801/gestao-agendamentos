<?php

namespace App\Services\WhatsApp\Outbound;

use Illuminate\Support\Carbon;

readonly class WhatsAppOutboundReservation
{
    public function __construct(
        public bool $allowed,
        public ?Carbon $availableAt = null,
        public ?Carbon $retryAt = null,
        public ?string $reason = null,
    ) {}

    public static function ready(Carbon $availableAt): self
    {
        return new self(allowed: true, availableAt: $availableAt);
    }

    public static function retryLater(Carbon $retryAt, string $reason): self
    {
        return new self(allowed: false, retryAt: $retryAt, reason: $reason);
    }
}
