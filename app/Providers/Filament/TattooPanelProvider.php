<?php

namespace App\Providers\Filament;

use App\Filament\Tattoo\Pages\Auth\Login as TattooLogin;
use App\Support\Segment;
use Filament\Panel;
use Filament\Support\Colors\Color;

class TattooPanelProvider extends AppPanelProvider
{
    protected function panelId(): string
    {
        return Segment::panelId('tattoo');
    }

    protected function panelPath(): string
    {
        return 'painel';
    }

    public function panel(Panel $panel): Panel
    {
        return parent::panel($panel)
            ->domain((string) Segment::get('tattoo', 'domain'))
            ->login(TattooLogin::class)
            ->viteTheme('resources/css/filament/tattoo/theme.css')
            ->brandLogoHeight((string) Segment::get('tattoo', 'logo_height'))
            ->favicon(fn (): string => Segment::media('tattoo', 'favicon') ?? asset('images/estudio/favicon.svg'))
            ->colors([
                'primary' => Color::hex(Segment::themeColor('tattoo')),
            ]);
    }
}
