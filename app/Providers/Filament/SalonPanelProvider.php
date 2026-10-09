<?php

namespace App\Providers\Filament;

use App\Filament\Salon\Pages\Auth\Login as SalonLogin;
use App\Support\Segment;
use Filament\Panel;
use Filament\Support\Colors\Color;

class SalonPanelProvider extends AppPanelProvider
{
    protected function panelId(): string
    {
        return Segment::panelId('salon');
    }

    protected function panelPath(): string
    {
        return 'painel';
    }

    public function panel(Panel $panel): Panel
    {
        return parent::panel($panel)
            ->domain((string) Segment::get('salon', 'domain'))
            ->login(SalonLogin::class)
            ->viteTheme('resources/css/filament/salao/theme.css')
            ->brandLogoHeight((string) Segment::get('salon', 'logo_height'))
            ->favicon(fn (): string => Segment::media('salon', 'favicon') ?? asset('images/salao/favicon.svg'))
            ->colors([
                'primary' => Color::hex(Segment::themeColor('salon')),
            ]);
    }
}
