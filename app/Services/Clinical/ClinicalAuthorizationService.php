<?php

namespace App\Services\Clinical;

use App\Enums\CompanyModule;
use App\Enums\CompanyPermission;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Models\User;
use App\Services\Company\CompanyModuleService;
use App\Services\Company\CompanyPermissionService;

class ClinicalAuthorizationService
{
    public function __construct(
        protected CompanyPermissionService $permissions,
        protected CompanyModuleService $modules,
    ) {}

    public function authorize(User $user, Company $company, CompanyPermission $permission, ?Client $client = null): void
    {
        abort_unless($company->isDentalClinic() && $this->modules->hasModule($company, CompanyModule::ClinicalRecords), 403);
        abort_unless($this->permissions->allows($user, $company, $permission), 403);

        if ($client !== null) {
            abort_unless((int) $client->company_id === (int) $company->getKey(), 404);
            $this->authorizePatientScope($user, $company, $client);
        }
    }

    public function assertProfessional(Company $company, Professional $professional): void
    {
        abort_unless((int) $professional->company_id === (int) $company->getKey() && $professional->is_active, 422);
    }

    public function assertCanActAsProfessional(User $user, Company $company, Professional $professional): void
    {
        $this->assertProfessional($company, $professional);
        abort_unless((int) $professional->user_id === (int) $user->getKey(), 403);
    }

    protected function authorizePatientScope(User $user, Company $company, Client $client): void
    {
        $setting = $company->dentalClinicSetting()->first();

        if (($setting?->professional_record_scope ?? 'all') === 'all') {
            return;
        }

        $professional = Professional::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->first();

        if ($professional === null) {
            return;
        }

        $related = $client->appointments()->where('professional_id', $professional->getKey())->exists()
            || $client->clinicalEntries()->where('professional_id', $professional->getKey())->exists();

        abort_unless($related, 403);
    }
}
