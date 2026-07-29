<?php

namespace App\Enums;

enum SaleStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Partial = 'partial';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::Completed => 'Finalizada',
            self::Partial => 'Parcial',
            self::Paid => 'Paga',
            self::Cancelled => 'Cancelada',
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
