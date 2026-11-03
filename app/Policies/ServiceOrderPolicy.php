<?php

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\ServiceOrders\ServiceOrderService;
use Filament\Facades\Filament;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ServiceOrderPolicy
{
    public function viewAny(User $user): bool
    {
        $company = Filament::getTenant();
        if (! $company) {
            return false;
        }
        try {
            app(ServiceOrderService::class)->authorize($company, $user);

            return true;
        } catch (HttpException $e) {
            return false;
        }
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, ServiceOrder $order): bool
    {
        return (int) Filament::getTenant()?->id === (int) $order->company_id && $this->viewAny($user);
    }

    public function update(User $user, ServiceOrder $order): bool
    {
        return $this->view($user, $order);
    }

    public function delete(User $user, ServiceOrder $order): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
