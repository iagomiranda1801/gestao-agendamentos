<?php

namespace App\Policies;

use App\Models\FinancialTransfer;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class FinancialTransferPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, FinancialTransfer $transfer): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $transfer->company)
            && $this->userCanViewFinancialRecords($user, $transfer->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanManageFinancialSettings($user);
    }

    public function update(User $user, FinancialTransfer $transfer): bool
    {
        return false;
    }

    public function delete(User $user, FinancialTransfer $transfer): bool
    {
        return false;
    }

    public function reverse(User $user, FinancialTransfer $transfer): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $transfer->company)
            && $this->userCanManageFinancialSettings($user, $transfer->company)
            && ! $transfer->isReversed();
    }
}
