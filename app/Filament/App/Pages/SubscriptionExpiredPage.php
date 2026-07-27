<?php

namespace App\Filament\App\Pages;

use App\Models\Company;
use App\Services\Company\CompanyModuleService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

class SubscriptionExpiredPage extends Page
{
    protected static ?string $slug = 'assinatura';

    protected static ?string $navigationLabel = 'Assinatura';

    protected static ?string $title = 'Assinatura expirada';

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected string $view = 'filament.app.pages.subscription-expired';

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return false;
        }

        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        if ($user->is_super_admin) {
            return true;
        }

        return ! app(CompanyModuleService::class)->isAccessAllowed($tenant);
    }

    public function getHeading(): string
    {
        return 'Seu período de teste terminou';
    }
}
