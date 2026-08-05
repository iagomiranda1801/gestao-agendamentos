<?php

namespace App\Policies;

use App\Enums\PayableStatus;
use App\Models\Payable;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class PayablePolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, Payable $payable): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $payable->company)
            && $this->userCanViewFinancialRecords($user, $payable->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function update(User $user, Payable $payable): bool
    {
        return $this->view($user, $payable) && $payable->isDraft();
    }

    public function delete(User $user, Payable $payable): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function launch(User $user, Payable $payable): bool
    {
        return $this->view($user, $payable) && $payable->isDraft();
    }

    public function cancel(User $user, Payable $payable): bool
    {
        return $this->view($user, $payable)
            && $payable->origin->value === 'manual'
            && $payable->status !== PayableStatus::Cancelled;
    }

    public function registerPayment(User $user, Payable $payable): bool
    {
        if (! $this->view($user, $payable)) {
            return false;
        }

        return ! in_array($payable->status, [PayableStatus::Draft, PayableStatus::Cancelled, PayableStatus::Paid], true);
    }
}
