<?php

namespace App\Enums;

enum CashSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberto',
            self::Closed => 'Fechado',
        };
    }
}
