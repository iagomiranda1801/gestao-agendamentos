<?php

namespace App\Filament\Admin\Pages;

use App\Services\Admin\AdminDashboardAggregator;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Dashboard';

    protected static ?string $title = 'Dashboard';

    public function getTitle(): string
    {
        return 'Dashboard Admin';
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                ViewComponent::make('filament.admin.pages.dashboard')
                    ->viewData([
                        'dashboard' => app(AdminDashboardAggregator::class)->aggregate(),
                        'userName' => auth()->user()?->name ?? 'Admin',
                    ]),
            ]);
    }
}
