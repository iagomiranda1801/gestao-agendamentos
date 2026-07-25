<?php

namespace App\Support;

use App\Models\Company;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class CompanyDateTime
{
    public static function timezone(Company $company): string
    {
        return filled($company->timezone) ? $company->timezone : 'America/Sao_Paulo';
    }

    public static function nowLocal(Company $company): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone($company));
    }

    public static function nowUtc(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function parseLocal(Company $company, string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse(
            trim($date).' '.trim($time),
            self::timezone($company),
        );
    }

    public static function localToUtc(Company $company, CarbonInterface $local): CarbonImmutable
    {
        return CarbonImmutable::instance($local)
            ->setTimezone(self::timezone($company))
            ->utc();
    }

    public static function utcToLocal(Company $company, CarbonInterface $utc): CarbonImmutable
    {
        return CarbonImmutable::instance($utc)->utc()->setTimezone(self::timezone($company));
    }

    public static function formatLocal(Company $company, CarbonInterface $utc, string $format = 'd/m/Y H:i'): string
    {
        return self::utcToLocal($company, $utc)->format($format);
    }

    public static function timeToMinutes(string $time): int
    {
        $parts = explode(':', $time);

        return ((int) $parts[0] * 60) + (int) ($parts[1] ?? 0);
    }

    public static function minutesToTime(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d:00', $hours, $mins);
    }
}
