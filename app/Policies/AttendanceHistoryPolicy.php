<?php

namespace App\Policies;

use App\Models\AttendanceHistory;
use App\Models\User;

class AttendanceHistoryPolicy
{
    public function viewAny(User $user): bool
    {
        return (new AttendancePolicy)->viewAny($user);
    }

    public function view(User $user, AttendanceHistory $history): bool
    {
        $history->loadMissing('attendance');

        return $user->can('view', $history->attendance);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AttendanceHistory $history): bool
    {
        return false;
    }

    public function delete(User $user, AttendanceHistory $history): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
