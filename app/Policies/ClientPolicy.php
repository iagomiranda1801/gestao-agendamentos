<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Services\Company\CompanyPermissionService;
use Filament\Facades\Filament;

class ClientPolicy
{
    use AuthorizesCompanyRecords;

    protected function canManagePatients(User $user, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        return $company instanceof Company
            && app(CompanyPermissionService::class)->allows($user, $company, CompanyPermission::ManagePatients);
    }

    public function viewAny(User $user): bool
    {
        return $this->canManagePatients($user);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->recordBelongsToAccessibleTenant($user, $client->company)
            && $this->canManagePatients($user, $client->company);
    }

    public function create(User $user): bool
    {
        return $this->canManagePatients($user);
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
