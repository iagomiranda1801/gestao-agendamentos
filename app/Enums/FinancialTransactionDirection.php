<?php

namespace App\Enums;

enum FinancialTransactionDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';

    public function label(): string
    {
        return match ($this) {
            self::Inbound => 'Entrada',
            self::Outbound => 'Saída',
        };
    }
}
