<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Sale;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class SalePolicy
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

    public function view(User $user, Sale $sale): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $sale->company)
            && $this->userCanManageRecords($user, $sale->company, ...$this->allowedRoles());
    }

    public function create(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function cancel(User $user, Sale $sale): bool
    {
        return $this->view($user, $sale);
    }
}
