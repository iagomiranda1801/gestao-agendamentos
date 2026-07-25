<?php

namespace App\Policies;

use App\Models\CashSessionAdjustment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;
use App\Policies\Concerns\AuthorizesSchedulingAccess;

class CashSessionAdjustmentPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;
    use AuthorizesSchedulingAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user)
            || $this->userCanManageScheduling($user);
    }

    public function view(User $user, CashSessionAdjustment $adjustment): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $adjustment->company)
            && ($this->userCanViewFinancialRecords($user, $adjustment->company)
                || $this->userCanManageScheduling($user, $adjustment->company));
    }

    public function create(User $user): bool
    {
        return $this->userCanManageScheduling($user)
            || $this->userCanViewFinancialRecords($user);
    }
}
