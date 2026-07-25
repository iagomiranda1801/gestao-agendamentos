<?php

namespace App\Services\Company;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;

class CompanyMembershipGuard
{
    public function wouldRemoveLastActiveAdmin(Company $company, User $user, ?CompanyRole $newRole = null, ?bool $newIsActive = null): bool
    {
        $pivot = $company->users()
            ->where('users.id', $user->id)
            ->first()
            ?->pivot;

        if (! $pivot) {
            return false;
        }

        $currentRole = $pivot->role instanceof CompanyRole
            ? $pivot->role
            : CompanyRole::from($pivot->role);

        $currentIsActive = (bool) $pivot->is_active;

        if ($currentRole !== CompanyRole::CompanyAdmin || ! $currentIsActive) {
            return false;
        }

        $willRemainAdmin = ($newRole ?? $currentRole) === CompanyRole::CompanyAdmin
            && ($newIsActive ?? $currentIsActive);

        if ($willRemainAdmin) {
            return false;
        }

        return $company->activeAdminsCount() <= 1;
    }

    public function ensureCanRemoveLastActiveAdmin(Company $company, User $user, ?CompanyRole $newRole = null, ?bool $newIsActive = null): void
    {
        if ($this->wouldRemoveLastActiveAdmin($company, $user, $newRole, $newIsActive)) {
            throw new \InvalidArgumentException(
                'Não é possível remover ou desativar o último administrador ativo da empresa.'
            );
        }
    }
}
