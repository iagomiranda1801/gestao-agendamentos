<?php

namespace App\Enums;

enum ScheduleBlockType: string
{
    case Manual = 'manual';
    case DayOff = 'day_off';
    case Vacation = 'vacation';
    case Holiday = 'holiday';
    case Maintenance = 'maintenance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Bloqueio manual',
            self::DayOff => 'Folga',
            self::Vacation => 'Férias',
            self::Holiday => 'Feriado',
            self::Maintenance => 'Manutenção',
            self::Other => 'Outro',
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
