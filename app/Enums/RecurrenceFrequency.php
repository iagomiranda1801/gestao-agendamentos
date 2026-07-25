<?php

namespace App\Enums;

enum RecurrenceFrequency: string
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Semiannual = 'semiannual';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Semanal',
            self::Monthly => 'Mensal',
            self::Quarterly => 'Trimestral',
            self::Semiannual => 'Semestral',
            self::Yearly => 'Anual',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $frequency) => [$frequency->value => $frequency->label()])
            ->all();
    }
}
