<?php

namespace App\Enums;

enum PlatformInvoiceStatus: string
{
    case Open = 'open';
    case Overdue = 'overdue';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Aberta',
            self::Overdue => 'Vencida',
            self::Paid => 'Paga',
            self::Cancelled => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::Overdue => 'danger',
            self::Paid => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function isOutstanding(): bool
    {
        return $this === self::Open || $this === self::Overdue;
    }

    /**
     * @return list<self>
     */
    public static function outstanding(): array
    {
        return [self::Open, self::Overdue];
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
