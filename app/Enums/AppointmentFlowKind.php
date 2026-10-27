<?php

namespace App\Enums;

enum AppointmentFlowKind: string
{
    case Normal = 'normal';
    case QuickAttendance = 'quick_attendance';
    case FitIn = 'fit_in';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::QuickAttendance => 'Atender agora',
            self::FitIn => 'Encaixe',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $kind) => [$kind->value => $kind->label()])
            ->all();
    }
}
