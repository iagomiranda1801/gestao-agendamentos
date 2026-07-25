<?php

namespace App\Policies;

use App\Models\PayablePayment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class PayablePaymentPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, PayablePayment $payment): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $payment->company)
            && $this->userCanViewFinancialRecords($user, $payment->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function update(User $user, PayablePayment $payment): bool
    {
        return false;
    }

    public function delete(User $user, PayablePayment $payment): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function cancel(User $user, PayablePayment $payment): bool
    {
        if (! $this->view($user, $payment)) {
            return false;
        }

        return $payment->isConfirmed();
    }
}
