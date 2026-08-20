<?php

namespace App\Policies\Concerns;

use App\Enums\CompanyPermission;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyPermissionService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesClinicalRecords
{
    protected function allowsClinical(User $user, CompanyPermission $permission, ?Model $record = null): bool
    {
        $company = $record?->company ?? Filament::getTenant();

        return $company instanceof Company
            && (! $record || (int) $record->getAttribute('company_id') === (int) $company->getKey())
            && app(CompanyPermissionService::class)->allows($user, $company, $permission);
    }
}
