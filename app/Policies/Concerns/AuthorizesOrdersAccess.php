<?php

namespace App\Policies\Concerns;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyPermissionService;
use Filament\Facades\Filament;

trait AuthorizesOrdersAccess
{
    /**
     * @return list<CompanyRole>
     */
    protected function orderManagementRoles(): array
    {
        return [
            CompanyRole::CompanyAdmin,
            CompanyRole::Manager,
        ];
    }

    /**
     * @return list<CompanyRole>
     */
    protected function orderKitchenRoles(): array
    {
        return [
            CompanyRole::CompanyAdmin,
            CompanyRole::Manager,
            CompanyRole::Receptionist,
            CompanyRole::Employee,
        ];
    }

    protected function userCanManageOrders(User $user, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        if ($company instanceof Company && app(CompanyPermissionService::class)->allows($user, $company, CompanyPermission::ManageOrders)) {
            return true;
        }

        return $this->userCanManageRecords($user, $company, ...$this->orderManagementRoles());
    }

    protected function userCanViewOrders(User $user, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        if ($this->userCanManageOrders($user, $company instanceof Company ? $company : null)) {
            return true;
        }

        if ($company instanceof Company && app(CompanyPermissionService::class)->allows($user, $company, CompanyPermission::ViewOrders)) {
            return true;
        }

        return $this->userCanAccessKitchen($user, $company instanceof Company ? $company : null);
    }

    protected function userCanAccessKitchen(User $user, ?Company $company = null): bool
    {
        $company ??= Filament::getTenant();

        if ($company instanceof Company && app(CompanyPermissionService::class)->allows($user, $company, CompanyPermission::KitchenOrders)) {
            return true;
        }

        return $this->userCanManageRecords($user, $company, ...$this->orderKitchenRoles());
    }

    protected function userCanManageOrderSettings(User $user, ?Company $company = null): bool
    {
        return $this->userCanManageRecords($user, $company, CompanyRole::CompanyAdmin, CompanyRole::Manager);
    }
}
