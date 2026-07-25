<?php

namespace App\Policies;

use App\Models\CompanySchedulingSetting;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesSchedulingAccess;

class CompanySchedulingSettingPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesSchedulingAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanManageSettings($user);
    }

    public function view(User $user, CompanySchedulingSetting $setting): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $setting->company)
            && $this->userCanManageSettings($user, $setting->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanManageSettings($user);
    }

    public function update(User $user, CompanySchedulingSetting $setting): bool
    {
        return $this->view($user, $setting);
    }

    public function delete(User $user, CompanySchedulingSetting $setting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
