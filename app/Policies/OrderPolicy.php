<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesOrdersAccess;

class OrderPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesOrdersAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewOrders($user);
    }

    public function viewKitchen(User $user): bool
    {
        return $this->userCanAccessKitchen($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $order->company)
            && $this->userCanViewOrders($user, $order->company);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Order $order): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $order->company)
            && $this->userCanAccessKitchen($user, $order->company);
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function advance(User $user, Order $order): bool
    {
        return $this->update($user, $order);
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $order->company)
            && $this->userCanManageOrders($user, $order->company);
    }
}
