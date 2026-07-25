<?php

namespace App\Policies;

use App\Models\ExpenseCategory;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Policies\Concerns\AuthorizesFinancialAccess;

class ExpenseCategoryPolicy
{
    use AuthorizesCompanyRecords;
    use AuthorizesFinancialAccess;

    public function viewAny(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function view(User $user, ExpenseCategory $category): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $category->company)
            && $this->userCanViewFinancialRecords($user, $category->company);
    }

    public function create(User $user): bool
    {
        return $this->userCanViewFinancialRecords($user);
    }

    public function update(User $user, ExpenseCategory $category): bool
    {
        if (! $this->recordBelongsToAccessibleTenant($user, $category->company)) {
            return false;
        }

        if ($category->is_system) {
            return $this->userCanManageFinancialSettings($user, $category->company);
        }

        return $this->userCanViewFinancialRecords($user, $category->company);
    }

    public function delete(User $user, ExpenseCategory $category): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, ExpenseCategory $category): bool
    {
        return false;
    }

    public function forceDelete(User $user, ExpenseCategory $category): bool
    {
        return false;
    }
}
