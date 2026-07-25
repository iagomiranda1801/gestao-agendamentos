<?php

namespace App\Policies;

use App\Models\CashRegister;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class CashRegisterPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, CashRegister $cashRegister): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $cashRegister->company)
            && $this->userCanViewFinancialRecords($user, $cashRegister->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanManageFinancialSettings($user);
    }

    public function update(User $user, CashRegister $cashRegister): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $cashRegister->company)
            && $this->userCanManageFinancialSettings($user, $cashRegister->company);
    }

    public function delete(User $user, CashRegister $cashRegister): bool
    {
        return false;
    }
}
