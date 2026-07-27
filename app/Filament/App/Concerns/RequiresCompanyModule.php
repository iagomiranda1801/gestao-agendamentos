<?php

namespace App\Filament\App\Concerns;

use App\Enums\CompanyModule;
use App\Models\Company;
use App\Services\Company\CompanyModuleService;
use Filament\Facades\Filament;

trait RequiresCompanyModule
{
    abstract protected static function requiredCompanyModule(): CompanyModule;

    protected static function tenantHasRequiredModule(): bool
    {
        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return true;
        }

        $user = auth()->user();

        if ($user !== null && $user->is_super_admin) {
            return true;
        }

        return app(CompanyModuleService::class)->hasModule($tenant, static::requiredCompanyModule());
    }

    public static function shouldRegisterNavigation(): bool
    {
        if (! parent::shouldRegisterNavigation()) {
            return false;
        }

        return static::tenantHasRequiredModule();
    }
}
