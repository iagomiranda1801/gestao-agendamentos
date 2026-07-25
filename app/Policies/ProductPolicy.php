<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Product;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class ProductPolicy
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

    public function view(User $user, Product $product): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $product->company)
            && $this->userCanManageRecords($user, $product->company, ...$this->allowedRoles());
    }

    public function create(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function update(User $user, Product $product): bool
    {
        return $this->view($user, $product);
    }

    public function delete(User $user, Product $product): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
