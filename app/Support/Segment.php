<?php

namespace App\Support;

use App\Models\Company;
use App\Models\SegmentSetting;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class Segment
{
    public const DEFAULT_PANEL = 'app';

    /** @var list<string> */
    private const STRUCTURAL_KEYS = ['enabled', 'domain', 'scheme', 'panel', 'profiles', 'label'];

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $settingsCache = null;

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys((array) config('segments', []));
    }

    public static function flush(): void
    {
        static::$settingsCache = null;
    }

    public static function get(string $segment, string $key, mixed $default = null): mixed
    {
        $configValue = config("segments.{$segment}.{$key}", $default);

        if (in_array($key, self::STRUCTURAL_KEYS, true)) {
            return $configValue;
        }

        $overlay = static::overlay($segment);

        if ($key === 'login') {
            return static::mergedLogin($configValue, $overlay);
        }

        $mapped = match ($key) {
            'logo' => $overlay['logo'] ?? null,
            'favicon' => $overlay['favicon'] ?? null,
            default => $overlay[$key] ?? null,
        };

        return filled($mapped) ? $mapped : $configValue;
    }

    public static function isEnabled(?string $segment): bool
    {
        return $segment !== null && (bool) static::get($segment, 'enabled', false);
    }

    public static function mainHost(): string
    {
        return (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'localhost');
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

    public static function mediaUrl(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, 'images/') || str_starts_with($path, '/')) {
            return '/'.ltrim($path, '/');
        }

        return Storage::disk('public')->url($path);
    }

    public static function media(string $segment, string $key): ?string
    {
        return static::mediaUrl(static::get($segment, $key));
    }

    public static function themeColor(string $segment): string
    {
        $color = (string) static::get($segment, 'primary_color', '#126bff');

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1 ? $color : '#126bff';
    }

    /**
     * @return array<string, mixed>
     */
    private static function overlay(string $segment): array
    {
        if (static::$settingsCache === null) {
            static::$settingsCache = static::loadOverlays();
        }

        return static::$settingsCache[$segment] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadOverlays(): array
    {
        if (! app()->bound('db') || ! Schema::hasTable('segment_settings')) {
            return [];
        }

        return SegmentSetting::query()
            ->get()
            ->mapWithKeys(fn (SegmentSetting $setting): array => [$setting->segment => $setting->overlay()])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $overlay
     * @return array<string, mixed>
     */
    private static function mergedLogin(mixed $configValue, array $overlay): array
    {
        $configLogin = is_array($configValue) ? $configValue : [];
        $dbLogin = array_filter(
            (array) ($overlay['login'] ?? []),
            fn (mixed $value): bool => $value !== null && $value !== '',
        );

        $merged = array_replace_recursive($configLogin, $dbLogin);

        if (filled($overlay['login_image_path'] ?? null)) {
            $merged['image'] = $overlay['login_image_path'];
        }

        return $merged;
    }
}
