<?php

namespace App\Enums;

enum CommissionType: string
{
    case Percentage = 'percentage';
    case Fixed = 'fixed';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Percentual',
            self::Fixed => 'Valor fixo',
            self::None => 'Sem comissão',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $type) => [$type->value => $type->label()])
            ->all();
    }
}
