<?php

namespace App\Policies\Concerns;

use App\Enums\CompanyRole;
use App\Models\Appointment;
use App\Models\Company;
use App\Models\Professional;
use App\Models\User;

trait AuthorizesSchedulingAccess
{
    /**
     * @return list<CompanyRole>
     */
    protected function managementRoles(): array
    {
        return [
            CompanyRole::CompanyAdmin,
            CompanyRole::Manager,
        ];
    }

    /**
     * @return list<CompanyRole>
     */
    protected function settingsRoles(): array
    {
        return [
            CompanyRole::CompanyAdmin,
        ];
    }

    protected function userCanManageScheduling(User $user, ?Company $company = null): bool
    {
        return $this->userCanManageRecords($user, $company, ...$this->managementRoles());
    }

    protected function userCanManageSettings(User $user, ?Company $company = null): bool
    {
        return $this->userCanManageRecords($user, $company, ...$this->settingsRoles());
    }

    protected function linkedProfessional(User $user, Company $company): ?Professional
    {
        return Professional::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->first();
    }

    protected function employeeCanAccessOwnAppointment(User $user, Appointment $appointment): bool
    {
        $professional = $this->linkedProfessional($user, $appointment->company);

        return $professional !== null
            && (int) $professional->getKey() === (int) $appointment->professional_id;
    }

    protected function employeeCanAccessCalendar(User $user, Company $company): bool
    {
        return $this->linkedProfessional($user, $company) !== null;
    }
}
