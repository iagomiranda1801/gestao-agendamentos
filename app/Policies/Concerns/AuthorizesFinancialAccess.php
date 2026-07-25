<?php

namespace App\Policies\Concerns;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;

trait AuthorizesFinancialAccess
{
    /**
     * @return list<CompanyRole>
     */
    protected function financialViewRoles(): array
    {
        return [
            CompanyRole::CompanyAdmin,
            CompanyRole::Manager,
        ];
    }

    /**
     * @return list<CompanyRole>
     */
    protected function financialSettingsRoles(): array
    {
        return [
            CompanyRole::CompanyAdmin,
        ];
    }

    protected function userCanViewFinancialRecords(User $user, ?Company $company = null): bool
    {
        return $this->userCanManageRecords($user, $company, ...$this->financialViewRoles());
    }

    protected function userCanManageFinancialSettings(User $user, ?Company $company = null): bool
    {
        return $this->userCanManageRecords($user, $company, ...$this->financialSettingsRoles());
    }
}
