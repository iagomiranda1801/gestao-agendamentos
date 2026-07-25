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
}
