<?php

namespace App\Policies;

use App\Models\CompanyFinancialSetting;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class CompanyFinancialSettingPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, CompanyFinancialSetting $setting): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $setting->company)
            && $this->userCanViewFinancialRecords($user, $setting->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanManageFinancialSettings($user);
    }

    public function update(User $user, CompanyFinancialSetting $setting): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $setting->company)
            && $this->userCanManageFinancialSettings($user, $setting->company);
    }

    public function delete(User $user, CompanyFinancialSetting $setting): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
