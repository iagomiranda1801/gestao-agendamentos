<?php

namespace App\Policies;

use App\Models\Receivable;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class ReceivablePolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, Receivable $receivable): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $receivable->company)
            && $this->userCanViewFinancialRecords($user, $receivable->company);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Receivable $receivable): bool
    {
        return false;
    }

    public function delete(User $user, Receivable $receivable): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function registerPayment(User $user, Receivable $receivable): bool
    {
        if (! $this->view($user, $receivable)) {
            return false;
        }

        if ($receivable->isSettled() || $receivable->isCancelled()) {
            return false;
        }

        return true;
    }
}
