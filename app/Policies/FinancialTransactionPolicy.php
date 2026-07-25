<?php

namespace App\Policies;

use App\Models\FinancialTransaction;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class FinancialTransactionPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, FinancialTransaction $transaction): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $transaction->company)
            && $this->userCanViewFinancialRecords($user, $transaction->company);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, FinancialTransaction $transaction): bool
    {
        return false;
    }

    public function delete(User $user, FinancialTransaction $transaction): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, FinancialTransaction $transaction): bool
    {
        return false;
    }

    public function forceDelete(User $user, FinancialTransaction $transaction): bool
    {
        return false;
    }
}
