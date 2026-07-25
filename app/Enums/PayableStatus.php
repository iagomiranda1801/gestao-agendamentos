<?php

namespace App\Enums;

enum PayableStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case Partial = 'partial';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Open => 'Em aberto',
            self::Partial => 'Parcialmente pago',
            self::Paid => 'Pago',
            self::Cancelled => 'Cancelado',
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
