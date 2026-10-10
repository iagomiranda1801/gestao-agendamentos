<?php

namespace App\Support;

use App\Models\Company;

class Pwa
{
    /**
     * @return array<string, mixed>
     */
    public static function panelManifest(): array
    {
        $segment = Segment::current();
        $path = static::panelPath($segment);

        return [
            'name' => Branding::name(),
            'short_name' => static::shortName(Branding::name()),
            'start_url' => $path,
            'scope' => $path,
            'display' => 'standalone',
            'theme_color' => static::themeColor($segment),
            'background_color' => static::backgroundColor($segment),
            'icons' => static::icons($segment),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function clientManifest(Company $company, string $kind): array
    {
        $path = $kind === 'orders'
            ? '/pedir/'.$company->slug
            : '/agendar/'.$company->slug;

        $segment = Segment::forCompany($company);

        return [
            'name' => $company->name,
            'short_name' => static::shortName($company->name),
            'start_url' => $path,
            'scope' => $path,
            'display' => 'standalone',
            'theme_color' => $segment ? Segment::themeColor($segment) : '#2563eb',
            'background_color' => static::backgroundColor($segment),
            'icons' => static::icons($segment, $company),
        ];
    }

    /**
     * @return array{manifest: string, themeColor: string, backgroundColor: string, appleTouchIcon: string, scope: string, name: string}
     */
    public static function head(?Company $company = null, string $surface = 'panel'): array
    {
        $segment = $surface === 'panel' ? Segment::current() : Segment::forCompany($company);

        if ($surface === 'booking' && $company instanceof Company) {
            $manifest = '/agendar/'.$company->slug.'/manifest.webmanifest';
            $scope = '/agendar/'.$company->slug;
            $name = $company->name;
        } elseif ($surface === 'orders' && $company instanceof Company) {
            $manifest = '/pedir/'.$company->slug.'/manifest.webmanifest';
            $scope = '/pedir/'.$company->slug;
            $name = $company->name;
        } else {
            $manifest = '/manifest.webmanifest';
            $scope = static::panelPath($segment);
            $name = Branding::name();
        }

        $icons = static::icons($segment, $company);

        return [
            'manifest' => $manifest,
            'themeColor' => $surface === 'panel'
                ? static::themeColor($segment)
                : ($segment ? Segment::themeColor($segment) : '#2563eb'),
            'backgroundColor' => static::backgroundColor($segment),
            'appleTouchIcon' => $icons[1]['src'] ?? $icons[0]['src'] ?? '/images/agendaqui/icon-192.png',
            'scope' => $scope,
            'name' => $name,
        ];
    }

    public static function panelPath(?string $segment): string
    {
        if ($segment !== null && filled(Segment::get($segment, 'panel'))) {
            return '/painel';
        }

        return '/app';
    }

    public static function themeColor(?string $segment): string
    {
        return $segment !== null ? Segment::themeColor($segment) : '#126bff';
    }

    public static function backgroundColor(?string $segment): string
    {
        return $segment === 'tattoo' ? '#0f0d0b' : '#ffffff';
    }

    /**
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    public static function icons(?string $segment, ?Company $company = null): array
    {
        $logo = $company?->logoUrl();

        if (static::isRaster($logo)) {
            $src = static::publicSrc((string) $logo);

            return [
                ['src' => $src, 'sizes' => '192x192', 'type' => static::imageType($src), 'purpose' => 'any'],
                ['src' => $src, 'sizes' => '512x512', 'type' => static::imageType($src), 'purpose' => 'any'],
            ];
        }

        $folder = match ($segment) {
            'salon' => 'salao',
            'tattoo' => 'estudio',
            default => 'agendaqui',
        };

        return [
            ['src' => "/images/{$folder}/icon-192.png", 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => "/images/{$folder}/icon-512.png", 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ];
    }

    /**
     * @return array{name: string, themeColor: string, backgroundColor: string, ink: string, muted: string}
     */
    public static function offlineViewData(): array
    {
        $segment = Segment::current();
        $dark = $segment === 'tattoo';

        return [
            'name' => Branding::name(),
            'themeColor' => static::themeColor($segment),
            'backgroundColor' => static::backgroundColor($segment),
            'ink' => $dark ? '#f4efe6' : '#111827',
            'muted' => $dark ? '#b3a898' : '#6b7280',
        ];
    }

    public static function shortName(string $name): string
    {
        $name = trim($name);

        return mb_strlen($name) <= 12 ? $name : rtrim(mb_substr($name, 0, 12));
    }

    private static function isRaster(?string $url): bool
    {
        if (blank($url)) {
            return false;
        }

        $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: $url));

        return (bool) preg_match('/\.(png|jpe?g|webp)$/', $path);
    }

    private static function publicSrc(string $url): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            $path = parse_url($url, PHP_URL_PATH);

            return is_string($path) && $path !== '' ? $path : $url;
        }

        return str_starts_with($url, '/') ? $url : '/'.$url;
    }

    private static function imageType(string $src): string
    {
        return match (true) {
            str_ends_with(strtolower($src), '.webp') => 'image/webp',
            str_ends_with(strtolower($src), '.jpg'), str_ends_with(strtolower($src), '.jpeg') => 'image/jpeg',
            default => 'image/png',
        };
    }
}
