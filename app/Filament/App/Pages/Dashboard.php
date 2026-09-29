<?php

namespace App\Filament\App\Pages;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Services\Company\PersonalTrainerSetupService;
use App\Services\Dashboard\OperationalDashboardAggregator;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\View as ViewComponent;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = 'Início';

    protected static ?string $title = 'Início';

    public function getTitle(): string
    {
        return Filament::getTenant()?->name ?? 'Início';
    }

    public function content(Schema $schema): Schema
    {
        /** @var Company|null $company */
        $company = Filament::getTenant();

        return $schema
            ->components([
                ViewComponent::make('filament.app.pages.dashboard')
                    ->viewData([
                        'dashboard' => $company
                            ? app(OperationalDashboardAggregator::class)->aggregate($company)
                            : [],
                        'userName' => auth()->user()?->name ?? 'Usuário',
                        'personalTrainerSetup' => $company && auth()->user()?->hasActiveRoleInCompany(
                            $company,
                            CompanyRole::CompanyAdmin,
                            CompanyRole::Manager,
                        )
                            ? app(PersonalTrainerSetupService::class)->summary($company)
                            : ['steps' => [], 'templates' => [], 'booking_url' => null],
                    ]),
            ]);
    }
}
