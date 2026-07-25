<?php

namespace App\Enums;

enum MeasurementUnitCategory: string
{
    case Count = 'count';
    case Volume = 'volume';
    case Mass = 'mass';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Count => 'Contagem',
            self::Volume => 'Volume',
            self::Mass => 'Massa',
            self::Custom => 'Personalizada',
        };
    }
}
