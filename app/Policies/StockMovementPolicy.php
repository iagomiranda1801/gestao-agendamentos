<?php

namespace App\Policies;

use App\Models\StockMovement;
use App\Models\User;

class StockMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return (new StockDocumentPolicy)->viewAny($user);
    }

    public function view(User $user, StockMovement $movement): bool
    {
        return (new StockDocumentPolicy)->view($user, $movement->document);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function delete(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function forceDelete(User $user, StockMovement $movement): bool
    {
        return false;
    }

    public function restore(User $user, StockMovement $movement): bool
    {
        return false;
    }
}
