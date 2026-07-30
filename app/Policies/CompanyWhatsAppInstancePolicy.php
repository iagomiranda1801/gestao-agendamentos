<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\CompanyWhatsAppInstance;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class CompanyWhatsAppInstancePolicy
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

    public function view(User $user, CompanyWhatsAppInstance $instance): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $instance->company)
            && $this->userCanManageRecords($user, $instance->company, ...$this->allowedRoles());
    }

    public function create(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function update(User $user, CompanyWhatsAppInstance $instance): bool
    {
        return $this->view($user, $instance);
    }

    public function delete(User $user, CompanyWhatsAppInstance $instance): bool
    {
        return $this->view($user, $instance);
    }
}
