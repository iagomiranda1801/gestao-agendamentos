<?php

namespace App\Policies;

use App\Models\ModulePrice;
use App\Models\User;

class ModulePricePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function view(User $user, ModulePrice $price): bool
    {
        return $user->isPlatformAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ModulePrice $price): bool
    {
        return $user->isPlatformAdmin();
    }

    public function delete(User $user, ModulePrice $price): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
