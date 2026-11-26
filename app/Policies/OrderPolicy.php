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

    public function operateFloor(User $user): bool
    {
        return $this->userCanAccessKitchen($user);
    }

    public function manageFloor(User $user, Order $order): bool
    {
        return $order->isFloorOrder()
            && $order->isOpen()
            && $this->recordBelongsToAccessibleTenant($user, $order->company)
            && $this->userCanAccessKitchen($user, $order->company);
    }

    public function cancelFloor(User $user, Order $order): bool
    {
        return $this->manageFloor($user, $order);
    }

    public function printReceipt(User $user, Order $order): bool
    {
        if (! $this->recordBelongsToAccessibleTenant($user, $order->company)) {
            return false;
        }

        if ($this->userCanViewOrders($user, $order->company)) {
            return true;
        }

        return $order->isFloorOrder()
            && $this->userCanAccessKitchen($user, $order->company);
    }
}
