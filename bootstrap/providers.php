<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\AppPanelProvider;
use App\Providers\Filament\SalonPanelProvider;
use App\Providers\Filament\TattooPanelProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TelescopeServiceProvider;
use Laravel\Horizon\HorizonApplicationServiceProvider;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    AppPanelProvider::class,
    SalonPanelProvider::class,
    TattooPanelProvider::class,
    ...(class_exists(HorizonApplicationServiceProvider::class) ? [
        HorizonServiceProvider::class,
    ] : []),
    ...(class_exists(TelescopeApplicationServiceProvider::class) ? [
        TelescopeServiceProvider::class,
    ] : []),
];
