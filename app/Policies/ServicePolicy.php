<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Service;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class ServicePolicy
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

    public function view(User $user, Service $service): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $service->company)
            && $this->userCanManageRecords($user, $service->company, ...$this->allowedRoles());
    }

    public function create(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function update(User $user, Service $service): bool
    {
        return $this->view($user, $service);
    }

    public function delete(User $user, Service $service): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
