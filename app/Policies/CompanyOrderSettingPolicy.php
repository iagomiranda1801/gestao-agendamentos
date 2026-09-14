<?php

namespace App\Policies;

use App\Models\CompanyOrderSetting;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesOrdersAccess;

class CompanyOrderSettingPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesOrdersAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanManageOrderSettings($user);
    }

    public function view(User $user, CompanyOrderSetting $setting): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $setting->company)
            && $this->userCanManageOrderSettings($user, $setting->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanManageOrderSettings($user);
    }

    public function update(User $user, CompanyOrderSetting $setting): bool
    {
        return $this->view($user, $setting);
    }

    public function delete(User $user, CompanyOrderSetting $setting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
