<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\InventoryBalance;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class InventoryBalancePolicy
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

    public function view(User $user, InventoryBalance $balance): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $balance->company)
            && $this->userCanManageRecords($user, $balance->company, ...$this->allowedRoles());
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, InventoryBalance $balance): bool
    {
        return false;
    }

    public function delete(User $user, InventoryBalance $balance): bool
    {
        return false;
    }
}
