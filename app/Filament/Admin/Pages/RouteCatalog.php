<?php

namespace App\Filament\Admin\Pages;

use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Routing\Route;
use UnitEnum;

class RouteCatalog extends Page
{
    protected static ?string $slug = 'operacao/rotas';

    protected static ?string $navigationLabel = 'Rotas do sistema';

    protected static ?string $title = 'Rotas do sistema';

    protected static string|UnitEnum|null $navigationGroup = 'Operação';

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.route-catalog';

    protected function getViewData(): array
    {
        return [
            'routes' => collect(app('router')->getRoutes()->getRoutes())
                ->map(fn (Route $route): array => [
                    'methods' => implode('|', array_values(array_diff($route->methods(), ['HEAD']))),
                    'uri' => $route->uri(),
                    'name' => $route->getName() ?: '-',
                    'action' => $route->getActionName(),
                ])
                ->filter(fn (array $route): bool => str_starts_with($route['name'], 'filament.app.')
                    || str_starts_with($route['name'], 'public.')
                    || $route['name'] === 'webhooks.evolution')
                ->sortBy(['name', 'uri'])
                ->values()
                ->all(),
        ];
    }
}
