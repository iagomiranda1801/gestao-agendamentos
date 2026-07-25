<?php

namespace App\Policies;

use App\Models\AttendanceMaterial;
use App\Models\User;

class AttendanceMaterialPolicy
{
    public function viewAny(User $user): bool
    {
        return (new AttendancePolicy)->viewAny($user);
    }

    public function view(User $user, AttendanceMaterial $material): bool
    {
        $material->loadMissing('attendance');

        return $user->can('view', $material->attendance);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AttendanceMaterial $material): bool
    {
        return false;
    }

    public function delete(User $user, AttendanceMaterial $material): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
