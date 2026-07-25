<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function view(User $user, Company $company): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function update(User $user, Company $company): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function deleteAny(User $user): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function restore(User $user, Company $company): bool
    {
        return $this->isSuperAdmin($user);
    }

    public function forceDelete(User $user, Company $company): bool
    {
        return $this->isSuperAdmin($user);
    }

    protected function isSuperAdmin(User $user): bool
    {
        return $user->is_active && $user->is_super_admin;
    }
}
