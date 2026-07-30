<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\User;
use App\Models\WhatsAppCampaign;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class WhatsAppCampaignPolicy
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

    public function view(User $user, WhatsAppCampaign $campaign): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $campaign->company)
            && $this->userCanManageRecords($user, $campaign->company, ...$this->allowedRoles());
    }

    public function create(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function update(User $user, WhatsAppCampaign $campaign): bool
    {
        return $this->view($user, $campaign);
    }

    public function delete(User $user, WhatsAppCampaign $campaign): bool
    {
        return $this->view($user, $campaign);
    }
}
