<?php

namespace App\Policies;

use App\Models\MenuCategory;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesOrdersAccess;

class MenuCategoryPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesOrdersAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanManageOrders($user);
    }

    public function view(User $user, MenuCategory $category): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $category->company)
            && $this->userCanManageOrders($user, $category->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanManageOrders($user);
    }

    public function update(User $user, MenuCategory $category): bool
    {
        return $this->view($user, $category);
    }

    public function delete(User $user, MenuCategory $category): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
