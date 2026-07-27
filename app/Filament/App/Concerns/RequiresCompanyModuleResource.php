<?php

namespace App\Filament\App\Concerns;

trait RequiresCompanyModuleResource
{
    use RequiresCompanyModule;

    public static function canViewAny(): bool
    {
        if (! parent::canViewAny()) {
            return false;
        }

        return static::tenantHasRequiredModule();
    }
}
