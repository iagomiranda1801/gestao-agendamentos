<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\Filament\AdminPanelProvider::class,
    App\Providers\Filament\AppPanelProvider::class,
    ...(class_exists(\Laravel\Telescope\TelescopeApplicationServiceProvider::class) ? [
        App\Providers\TelescopeServiceProvider::class,
    ] : []),
];
