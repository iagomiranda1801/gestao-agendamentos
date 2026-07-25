<?php

namespace App\Policies;

use App\Models\CashSession;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;
use App\Policies\Concerns\AuthorizesSchedulingAccess;

class CashSessionPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;
    use AuthorizesSchedulingAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user)
            || $this->userCanManageScheduling($user);
    }

    public function view(User $user, CashSession $session): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $session->company)
            && ($this->userCanViewFinancialRecords($user, $session->company)
                || $this->userCanManageScheduling($user, $session->company));
    }

    public function open(User $user): bool
    {
        return $this->userCanManageScheduling($user)
            || $this->userCanViewFinancialRecords($user);
    }

    public function close(User $user, CashSession $session): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $session->company)
            && ($this->userCanManageScheduling($user, $session->company)
                || $this->userCanViewFinancialRecords($user, $session->company));
    }

    public function adjust(User $user, CashSession $session): bool
    {
        return $this->close($user, $session);
    }
}
