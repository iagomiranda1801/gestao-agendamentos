<?php

namespace App\Support;

use App\Models\Company;

class Segment
{
    public const DEFAULT_PANEL = 'app';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys((array) config('segments', []));
    }

    public static function get(string $segment, string $key, mixed $default = null): mixed
    {
        return config("segments.{$segment}.{$key}", $default);
    }

    public static function isEnabled(?string $segment): bool
    {
        return $segment !== null && (bool) static::get($segment, 'enabled', false);
    }

    public static function current(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        return static::forHost(request()->getHost());
    }

    public static function forHost(?string $host): ?string
    {
        if (blank($host)) {
            return null;
        }

        foreach (static::keys() as $segment) {
            if (strcasecmp($host, (string) static::get($segment, 'domain')) === 0) {
                return $segment;
            }
        }

        return null;
    }

    public static function forCompany(?Company $company): ?string
    {
        $profile = $company?->business_profile?->value;

        if ($profile === null) {
            return null;
        }

        foreach (static::keys() as $segment) {
            if (in_array($profile, (array) static::get($segment, 'profiles', []), true)) {
                return $segment;
            }
        }

        return null;
    }

    public static function acceptsCompany(string $segment, Company $company): bool
    {
        return static::forCompany($company) === $segment;
    }

    public static function panelId(string $segment): string
    {
        return (string) static::get($segment, 'panel');
    }

    public static function panelIdForCompany(?Company $company): string
    {
        $segment = static::forCompany($company);

        return static::isEnabled($segment) ? static::panelId($segment) : self::DEFAULT_PANEL;
    }

    public static function baseUrl(string $segment): string
    {
        return static::get($segment, 'scheme', 'https').'://'.static::get($segment, 'domain');
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function route(?Company $company, string $name, array $parameters = []): string
    {
        $segment = static::forCompany($company);

        if (! static::isEnabled($segment)) {
            return route($name, $parameters);
        }

        return static::baseUrl($segment).route($name, $parameters, absolute: false);
    }
}
