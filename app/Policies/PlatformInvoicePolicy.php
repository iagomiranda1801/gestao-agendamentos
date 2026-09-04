<?php

namespace App\Policies;

use App\Models\PlatformInvoice;
use App\Models\User;

class PlatformInvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function view(User $user, PlatformInvoice $invoice): bool
    {
        return $user->isPlatformAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function update(User $user, PlatformInvoice $invoice): bool
    {
        return $user->isPlatformAdmin();
    }

    public function delete(User $user, PlatformInvoice $invoice): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
