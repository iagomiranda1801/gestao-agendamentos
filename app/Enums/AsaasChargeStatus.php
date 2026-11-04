<?php

namespace App\Enums;

enum AsaasChargeStatus: string
{
    case Pending = 'pending';
    case Received = 'received';
    case Confirmed = 'confirmed';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function settlesPayment(): bool
    {
        return $this === self::Received || $this === self::Confirmed;
    }
}
