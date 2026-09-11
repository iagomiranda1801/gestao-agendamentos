<?php

namespace App\Support;

class PhoneNormalizer
{
    public static function normalize(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $normalized = preg_replace('/\D+/', '', $phone) ?? '';

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Variantes BR usadas no WhatsApp (com/sem 55 e com/sem o nono dígito).
     *
     * @return list<string>
     */
    public static function candidates(?string $phone): array
    {
        $digits = self::normalize($phone);

        if ($digits === null) {
            return [];
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        $candidates = [$digits, '55'.$digits];

        if (strlen($digits) === 11 && $digits[2] === '9') {
            $withoutNinth = substr($digits, 0, 2).substr($digits, 3);
            $candidates[] = $withoutNinth;
            $candidates[] = '55'.$withoutNinth;
        } elseif (strlen($digits) === 10) {
            $withNinth = substr($digits, 0, 2).'9'.substr($digits, 2);
            $candidates[] = $withNinth;
            $candidates[] = '55'.$withNinth;
        }

        return array_values(array_unique(array_filter($candidates)));
    }
}
