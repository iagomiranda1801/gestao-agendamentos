<?php

namespace App\Enums;

enum ClinicalSpecialty: string
{
    case Psychology = 'psychology';
    case Medicine = 'medicine';
    case Psychiatry = 'psychiatry';
    case Nutrition = 'nutrition';
    case Dentistry = 'dentistry';

    public function label(): string
    {
        return match ($this) {
            self::Psychology => 'Psicologia',
            self::Medicine => 'Medicina',
            self::Psychiatry => 'Psiquiatria',
            self::Nutrition => 'Nutrição',
            self::Dentistry => 'Odontologia',
        };
    }

    public function roleLabel(): string
    {
        return match ($this) {
            self::Psychology => 'Psicólogo',
            self::Medicine => 'Médico',
            self::Psychiatry => 'Psiquiatra',
            self::Nutrition => 'Nutricionista',
            self::Dentistry => 'Dentista',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $specialty): array => [$specialty->value => $specialty->label()])
            ->all();
    }
}
