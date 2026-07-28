<?php

namespace App\Support;

class Branding
{
    public static function name(): string
    {
        return (string) config('branding.name', 'Agendaqui');
    }

    public static function tagline(): string
    {
        return (string) config('branding.tagline', 'Gestão de agendamentos para o seu negócio');
    }

    public static function logoUrl(): string
    {
        return asset((string) config('branding.logo', 'images/aqui.png'));
    }

    public static function faviconUrl(): string
    {
        return asset((string) config('branding.favicon', config('branding.logo', 'images/aqui.png')));
    }

    public static function logoHeight(): string
    {
        return (string) config('branding.logo_height', '2.25rem');
    }

    public static function loginShowcaseImageUrl(): string
    {
        return asset((string) config('branding.login_showcase_image', 'images/image-login.png'));
    }
}
