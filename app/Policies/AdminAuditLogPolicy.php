<?php

namespace App\Policies;

use App\Models\AdminAuditLog;
use App\Models\User;

class AdminAuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isPlatformAdmin();
    }

    public function view(User $user, AdminAuditLog $log): bool
    {
        return $user->isPlatformAdmin();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AdminAuditLog $log): bool
    {
        return false;
    }

    public function delete(User $user, AdminAuditLog $log): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
