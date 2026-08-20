<?php

namespace App\Services\Company;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;

class CompanyPermissionService
{
    public function allows(User $user, Company $company, CompanyPermission $permission): bool
    {
        $membership = $company->users()
            ->where('users.id', $user->getKey())
            ->wherePivot('is_active', true)
            ->first();

        if ($membership === null) {
            return false;
        }

        $explicit = $membership->pivot->permissions;

        if (is_array($explicit)) {
            return in_array($permission->value, $explicit, true);
        }

        $role = $membership->pivot->role;
        $role = $role instanceof CompanyRole ? $role : CompanyRole::tryFrom((string) $role);

        return $role !== null
            && in_array($permission, CompanyPermission::defaultsForRole($role), true);
    }
}
