<?php

namespace App\Policies;

use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\TattooRequest;
use App\Models\User;
use App\Policies\Concerns\AuthorizesCompanyRecords;
use App\Services\Company\CompanyPermissionService;
use Filament\Facades\Filament;

class TattooRequestPolicy
{
    use AuthorizesCompanyRecords;

    public function viewAny(User $user): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company && $company->isTattooStudio()
            && $this->recordBelongsToAccessibleTenant($user, $company);
    }

    public function view(User $user, TattooRequest $request): bool
    {
        return $this->viewAny($user)
            && (int) Filament::getTenant()->getKey() === (int) $request->company_id
            && ($this->canManage($user, $request->company) || $this->isAssigned($user, $request));
    }

    public function create(User $user): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Company && $company->isTattooStudio() && $this->canManage($user, $company);
    }

    public function update(User $user, TattooRequest $request): bool
    {
        return $this->view($user, $request);
    }

    public function delete(User $user, TattooRequest $request): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    protected function canManage(User $user, Company $company): bool
    {
        return $user->is_super_admin
            || app(CompanyPermissionService::class)->allows($user, $company, CompanyPermission::ManageAppointments);
    }

    protected function isAssigned(User $user, TattooRequest $request): bool
    {
        return $request->professional_id !== null
            && (int) $request->professional?->user_id === (int) $user->getKey();
    }
}
