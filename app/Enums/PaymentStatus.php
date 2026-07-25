<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Confirmed => 'Confirmado',
            self::Cancelled => 'Cancelado',
            self::Refunded => 'Reembolsado',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status) => [$status->value => $status->label()])
            ->all();
    }
}
