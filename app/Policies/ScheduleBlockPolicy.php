<?php

namespace App\Policies;

use App\Models\ScheduleBlock;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesSchedulingAccess;

class ScheduleBlockPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesSchedulingAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanManageScheduling($user);
    }

    public function view(User $user, ScheduleBlock $block): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $block->company)
            && $this->userCanManageScheduling($user, $block->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanManageScheduling($user);
    }

    public function update(User $user, ScheduleBlock $block): bool
    {
        return $this->view($user, $block);
    }

    public function delete(User $user, ScheduleBlock $block): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
