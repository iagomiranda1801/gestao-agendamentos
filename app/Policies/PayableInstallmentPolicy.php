<?php

namespace App\Policies;

use App\Enums\PayableStatus;
use App\Models\PayableInstallment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class PayableInstallmentPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, PayableInstallment $installment): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $installment->company)
            && $this->userCanViewFinancialRecords($user, $installment->company);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, PayableInstallment $installment): bool
    {
        return false;
    }

    public function delete(User $user, PayableInstallment $installment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function registerPayment(User $user, PayableInstallment $installment): bool
    {
        if (! $this->view($user, $installment)) {
            return false;
        }

        return $installment->status !== PayableStatus::Paid;
    }
}
