<?php

namespace App\Policies;

use App\Models\FinancialAccount;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class FinancialAccountPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, FinancialAccount $account): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $account->company)
            && $this->userCanViewFinancialRecords($user, $account->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanManageFinancialSettings($user);
    }

    public function update(User $user, FinancialAccount $account): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $account->company)
            && $this->userCanManageFinancialSettings($user, $account->company);
    }

    public function delete(User $user, FinancialAccount $account): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, FinancialAccount $account): bool
    {
        return false;
    }

    public function forceDelete(User $user, FinancialAccount $account): bool
    {
        return false;
    }
}
