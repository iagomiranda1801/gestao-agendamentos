<?php

namespace App\Support;

class Branding
{
    public static function name(): string
    {
        return (string) static::value('name', 'Agendaqui');
    }

    public static function tagline(): string
    {
        return (string) static::value('tagline', 'Gestão de agendamentos para o seu negócio');
    }

    public static function logoUrl(): string
    {
        return asset((string) static::value('logo', 'images/aqui.png'));
    }

    public static function faviconUrl(): string
    {
        return asset((string) static::value('favicon', static::value('logo', 'images/aqui.png')));
    }

    public static function logoHeight(): string
    {
        return (string) static::value('logo_height', '2.25rem');
    }

    public static function loginShowcaseImageUrl(): string
    {
        return asset((string) config('branding.login_showcase_image', 'images/image-login.png'));
    }

    public static function segment(): ?string
    {
        return Segment::current();
    }

    public static function value(string $key, mixed $default = null): mixed
    {
        $segment = static::segment();

        if ($segment !== null && filled(Segment::get($segment, $key))) {
            return Segment::get($segment, $key);
        }

        return config("branding.{$key}", $default);
    }
}
