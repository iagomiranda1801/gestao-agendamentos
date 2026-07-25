<?php

namespace App\Enums;

enum PayablePaymentStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmado',
            self::Cancelled => 'Cancelado',
        };
    }
}
