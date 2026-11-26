<?php

namespace App\Policies;

use App\Models\DiningTable;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesOrdersAccess;

class DiningTablePolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesOrdersAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanManageOrders($user);
    }

    public function view(User $user, DiningTable $table): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $table->company)
            && $this->userCanManageOrders($user, $table->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanManageOrders($user);
    }

    public function update(User $user, DiningTable $table): bool
    {
        return $this->view($user, $table);
    }

    public function delete(User $user, DiningTable $table): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
