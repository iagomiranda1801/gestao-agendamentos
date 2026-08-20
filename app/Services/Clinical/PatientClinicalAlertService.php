<?php

namespace App\Services\Clinical;

use App\Enums\CompanyPermission;
use App\Models\Client;
use App\Models\Company;
use App\Models\PatientClinicalAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PatientClinicalAlertService
{
    public function __construct(protected ClinicalAuthorizationService $authorization, protected ClinicalAuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function create(Company $company, Client $client, User $user, array $data): PatientClinicalAlert
    {
        $this->authorization->authorize($user, $company, CompanyPermission::WriteClinicalRecords, $client);

        return DB::transaction(function () use ($company, $client, $user, $data): PatientClinicalAlert {
            unset($data['company_id'], $data['client_id'], $data['created_by'], $data['source_type'], $data['source_id']);
            $alert = new PatientClinicalAlert($data + ['is_active' => true, 'type' => 'free', 'severity' => 'attention']);
            $alert->company_id = $company->getKey();
            $alert->client_id = $client->getKey();
            $alert->created_by = $user->getKey();
            $alert->save();
            $this->audit->record($company, $client, $user, 'clinical_alert.created', $alert);

            return $alert->refresh();
        });
    }

    public function deactivate(Company $company, PatientClinicalAlert $alert, User $user): PatientClinicalAlert
    {
        abort_unless((int) $alert->company_id === (int) $company->getKey(), 404);
        $client = Client::query()->where('company_id', $company->getKey())->findOrFail($alert->client_id);
        $this->authorization->authorize($user, $company, CompanyPermission::WriteClinicalRecords, $client);

        return DB::transaction(function () use ($company, $client, $alert, $user): PatientClinicalAlert {
            $alert->update(['is_active' => false, 'deactivated_by' => $user->getKey(), 'deactivated_at' => now()]);
            $this->audit->record($company, $client, $user, 'clinical_alert.deactivated', $alert);

            return $alert->refresh();
        });
    }
}
