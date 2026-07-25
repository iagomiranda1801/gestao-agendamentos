<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Professional;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class ProfessionalPolicy
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

    public function view(User $user, Professional $professional): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $professional->company)
            && $this->userCanManageRecords($user, $professional->company, ...$this->allowedRoles());
    }

    public function create(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function update(User $user, Professional $professional): bool
    {
        return $this->view($user, $professional);
    }

    public function delete(User $user, Professional $professional): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Professional $professional): bool
    {
        return false;
    }

    public function forceDelete(User $user, Professional $professional): bool
    {
        return false;
    }
}
