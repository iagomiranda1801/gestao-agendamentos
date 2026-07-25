<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Dashboard;
use App\Models\Company;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('app')
            ->path('app')
            ->login()
            ->brandName(fn (): string => filament()->getTenant()?->name ?? 'Gestão de Agendamentos')
            ->colors([
                'primary' => Color::Amber,
            ])
            ->assets([
                Css::make('panel-fixes', resource_path('css/filament-panel-fixes.css')),
            ])
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn (): string => view('filament.hooks.notification-fallback')->render())
            ->navigationGroups([
                NavigationGroup::make('Cadastros')->collapsed(),
                NavigationGroup::make('Agenda')->collapsed(),
                NavigationGroup::make('Estoque')->collapsed(),
                NavigationGroup::make('Financeiro')->collapsed(),
                NavigationGroup::make('Caixa')->collapsed(),
                NavigationGroup::make('Relatórios')->collapsed(),
                NavigationGroup::make('Configurações')->collapsed(),
            ])
            ->collapsibleNavigationGroups()
            ->databaseNotifications()
            ->tenant(Company::class, slugAttribute: 'slug')
            ->tenantRoutePrefix('empresa')
            ->tenantSwitcher()
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\Filament\App\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\Filament\App\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
