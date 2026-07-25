<?php

namespace App\Policies;

use App\Models\AppointmentHistory;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesSchedulingAccess;

class AppointmentHistoryPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesSchedulingAccess;

    public function viewAny(User $user): bool
    {
        return (new AppointmentPolicy)->viewAny($user);
    }

    public function view(User $user, AppointmentHistory $history): bool
    {
        return (new AppointmentPolicy)->view($user, $history->appointment);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AppointmentHistory $history): bool
    {
        return false;
    }

    public function delete(User $user, AppointmentHistory $history): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
