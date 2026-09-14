<?php

namespace App\Policies;

use App\Models\OrderStatusHistory;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesOrdersAccess;

class OrderStatusHistoryPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesOrdersAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewOrders($user);
    }

    public function view(User $user, OrderStatusHistory $history): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $history->company)
            && $this->userCanViewOrders($user, $history->company);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, OrderStatusHistory $history): bool
    {
        return false;
    }

    public function delete(User $user, OrderStatusHistory $history): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
