<?php

namespace App\Policies;

use App\Models\StockDocumentItem;
use App\Models\User;

class StockDocumentItemPolicy
{
    public function viewAny(User $user): bool
    {
        return (new StockDocumentPolicy)->viewAny($user);
    }

    public function view(User $user, StockDocumentItem $item): bool
    {
        return (new StockDocumentPolicy)->view($user, $item->document);
    }

    public function create(User $user): bool
    {
        return (new StockDocumentPolicy)->create($user);
    }

    public function update(User $user, StockDocumentItem $item): bool
    {
        return (new StockDocumentPolicy)->update($user, $item->document);
    }

    public function delete(User $user, StockDocumentItem $item): bool
    {
        return false;
    }
}
