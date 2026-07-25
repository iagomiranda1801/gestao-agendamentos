<?php

namespace App\Enums;

enum FinancialDashboardPeriod: string
{
    case Today = 'today';
    case Week = 'week';
    case Month = 'month';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Hoje',
            self::Week => 'Esta semana',
            self::Month => 'Este mês',
            self::Custom => 'Personalizado',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $period) => [$period->value => $period->label()])
            ->all();
    }
}
