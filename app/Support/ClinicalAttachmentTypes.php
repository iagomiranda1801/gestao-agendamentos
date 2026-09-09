<?php

namespace App\Support;

class ClinicalAttachmentTypes
{
    /** @return array<string, string> */
    public static function options(): array
    {
        return [
            'radiograph' => 'Radiografia',
            'photo' => 'Fotografia',
            'exam' => 'Exame',
            'prescription' => 'Receita',
            'certificate' => 'Atestado',
            'consent' => 'Termo / consentimento',
            'meal_plan' => 'Plano alimentar',
            'general' => 'Documento geral',
        ];
    }

    public static function label(string $type): string
    {
        return self::options()[$type] ?? 'Documento geral';
    }
}
