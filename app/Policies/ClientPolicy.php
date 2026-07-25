<?php

namespace App\Policies;

use App\Enums\CompanyRole;
use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;

class ClientPolicy
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

    public function view(User $user, Client $client): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $client->company)
            && $this->userCanManageRecords($user, $client->company, ...$this->allowedRoles());
    }

    public function create(User $user): bool
    {
        return $this->userCanManageRecords($user, null, ...$this->allowedRoles());
    }

    public function update(User $user, Client $client): bool
    {
        return $this->view($user, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Client $client): bool
    {
        return false;
    }

    public function forceDelete(User $user, Client $client): bool
    {
        return false;
    }
}
