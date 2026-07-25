<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Supplier;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class SupplierPolicy
{
    use AuthorizesCompanyRecords;

    /**
     * @return list<CompanyRole>
     */
    protected function allowedRoles(): array
    {
        return [
            CompanyRole::CompanyAdmin,
            CompanyRole::Manager,
        ];
    }

    public function viewAny(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $supplier->company)
            && $this->userCanManageRecords($user, $supplier->company, ...$this->allowedRoles());
    }

    public function create(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $this->view($user, $supplier);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
