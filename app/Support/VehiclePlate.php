<?php

namespace App\Support;

class VehiclePlate
{
    public static function normalize(?string $plate): ?string
    {
        if ($plate === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    public static function isValid(?string $plate): bool
    {
        $normalized = self::normalize($plate);

        if ($normalized === null) {
            return true;
        }

        return (bool) preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $normalized);
    }

    public static function format(?string $plate): ?string
    {
        $normalized = self::normalize($plate);

        if ($normalized === null) {
            return null;
        }

        if (preg_match('/^([A-Z]{3})([0-9]{4})$/', $normalized, $matches) === 1) {
            return $matches[1].'-'.$matches[2];
        }

        return $normalized;
    }
}
