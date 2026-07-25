<?php

namespace App\Support;

class Cpf
{
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    public static function isValid(?string $value): bool
    {
        $cpf = self::normalize($value);

        if ($cpf === null || strlen($cpf) !== 11) {
            return false;
        }

        if (preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;

            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }

            $digit = ((10 * $sum) % 11) % 10;

            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }

    public static function format(?string $value): ?string
    {
        $cpf = self::normalize($value);

        if ($cpf === null || strlen($cpf) !== 11) {
            return $value;
        }

        return sprintf(
            '%s.%s.%s-%s',
            substr($cpf, 0, 3),
            substr($cpf, 3, 3),
            substr($cpf, 6, 3),
            substr($cpf, 9, 2),
        );
    }
}
