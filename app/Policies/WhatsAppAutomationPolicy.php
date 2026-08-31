<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\User;
use App\Models\WhatsAppAutomation;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class WhatsAppAutomationPolicy
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

    public function view(User $user, WhatsAppAutomation $automation): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $automation->company)
            && $this->userCanManageRecords($user, $automation->company, ...$this->allowedRoles());
    }

    public function update(User $user, WhatsAppAutomation $automation): bool
    {
        return $this->view($user, $automation);
    }
}
