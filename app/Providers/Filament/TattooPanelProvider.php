<?php

namespace App\Providers\Filament;

use App\Filament\Tattoo\Pages\Auth\Login as TattooLogin;
use App\Support\Segment;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;

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

    protected function panelDomain(): ?string
    {
        return (string) Segment::get('tattoo', 'domain');
    }

    public function panel(Panel $panel): Panel
    {
        return parent::panel($panel)
            ->login(TattooLogin::class)
            ->viteTheme('resources/css/filament/tattoo/theme.css')
            ->renderHook(PanelsRenderHook::BODY_START, fn (): string => '<script>document.documentElement.classList.add("dark")</script>')
            ->brandLogoHeight((string) Segment::get('tattoo', 'logo_height'))
            ->favicon(fn (): string => Segment::media('tattoo', 'favicon') ?? asset('images/estudio/favicon.svg'))
            ->colors([
                'primary' => Color::hex(Segment::themeColor('tattoo')),
            ]);
    }
}
