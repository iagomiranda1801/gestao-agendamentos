<?php

namespace App\Policies\Concerns;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;

trait AuthorizesCompanyRecords
{
    protected function userCanManageRecords(User $user, ?Company $company = null, CompanyRole ...$roles): bool
    {
        if (! $user->is_active) {
            return false;
        }

        $company ??= Filament::getTenant();

        if (! $company instanceof Company || ! $company->is_active) {
            return false;
        }

        if ($user->is_super_admin) {
            return $user->canAccessTenant($company);
        }

        return $user->hasActiveRoleInCompany($company, ...$roles);
    }

    protected function recordBelongsToAccessibleTenant(User $user, Company $recordCompany): bool
    {
        if (! $user->is_active || ! $recordCompany->is_active) {
            return false;
        }

        $tenant = Filament::getTenant();

        if (! $tenant instanceof Company) {
            return false;
        }

        if ((int) $tenant->getKey() !== (int) $recordCompany->getKey()) {
            return false;
        }

        if ($user->is_super_admin) {
            return $user->canAccessTenant($recordCompany);
        }

        return $user->hasActiveCompanyMembershipWith($recordCompany);
    }
}
