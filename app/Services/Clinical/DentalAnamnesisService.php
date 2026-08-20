<?php

namespace App\Services\Clinical;

use App\Enums\CompanyPermission;
use App\Enums\CompanyRole;
use App\Models\Client;
use App\Models\Company;
use App\Models\DentalAnamnesis;
use App\Models\PatientClinicalAlert;
use App\Models\Professional;
use App\Models\User;
use App\Support\DentalAnamnesisQuestionnaire;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DentalAnamnesisService
{
    public function __construct(
        protected ClinicalAuthorizationService $authorization,
        protected ClinicalAuditService $audit,
    ) {}

    /** @param array<string, mixed> $answers */
    public function createDraft(Company $company, Client $client, User $user, array $answers = []): DentalAnamnesis
    {
        $this->authorization->authorize($user, $company, CompanyPermission::WriteClinicalRecords, $client);

        return DB::transaction(function () use ($company, $client, $user, $answers): DentalAnamnesis {
            $version = (int) DentalAnamnesis::query()
                ->where('company_id', $company->getKey())
                ->where('client_id', $client->getKey())
                ->lockForUpdate()
                ->max('version') + 1;

            $anamnesis = new DentalAnamnesis([
                'version' => $version,
                'status' => 'draft',
                'questionnaire_snapshot' => DentalAnamnesisQuestionnaire::questions(),
                'answers' => $answers,
            ]);
            $anamnesis->company_id = $company->getKey();
            $anamnesis->client_id = $client->getKey();
            $anamnesis->created_by = $user->getKey();
            $anamnesis->save();

            $this->audit->record($company, $client, $user, 'anamnesis.created', $anamnesis, ['version' => $version]);

            return $anamnesis->refresh();
        });
    }

    /** @param array<string, mixed> $answers */
    public function updateDraft(Company $company, DentalAnamnesis $anamnesis, User $user, array $answers): DentalAnamnesis
    {
        $client = $this->clientFor($company, $anamnesis);
        $this->authorization->authorize($user, $company, CompanyPermission::WriteClinicalRecords, $client);
        $this->assertDraft($anamnesis);
        abort_unless(
            (int) $anamnesis->created_by === (int) $user->getKey()
                || $user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin),
            403,
        );

        return DB::transaction(function () use ($company, $client, $anamnesis, $user, $answers): DentalAnamnesis {
            $anamnesis->update(['answers' => $answers]);
            $this->audit->record($company, $client, $user, 'anamnesis.updated', $anamnesis);

            return $anamnesis->refresh();
        });
    }

    public function complete(Company $company, DentalAnamnesis $anamnesis, Professional $reviewer, User $user): DentalAnamnesis
    {
        $client = $this->clientFor($company, $anamnesis);
        $this->authorization->authorize($user, $company, CompanyPermission::FinalizeClinicalRecords, $client);
        $this->authorization->assertCanActAsProfessional($user, $company, $reviewer);
        $this->assertDraft($anamnesis);

        return DB::transaction(function () use ($company, $client, $anamnesis, $reviewer, $user): DentalAnamnesis {
            DentalAnamnesis::query()
                ->where('company_id', $company->getKey())
                ->where('client_id', $client->getKey())
                ->where('status', 'completed')
                ->update(['status' => 'superseded', 'superseded_at' => now()]);

            $anamnesis->update([
                'status' => 'completed',
                'reviewed_by' => $reviewer->getKey(),
                'completed_at' => now(),
            ]);

            $this->replaceDerivedAlerts($company, $client, $anamnesis, $user);
            $this->audit->record($company, $client, $user, 'anamnesis.completed', $anamnesis, ['version' => $anamnesis->version]);

            return $anamnesis->refresh();
        });
    }

    protected function replaceDerivedAlerts(Company $company, Client $client, DentalAnamnesis $anamnesis, User $user): void
    {
        PatientClinicalAlert::query()
            ->where('company_id', $company->getKey())
            ->where('client_id', $client->getKey())
            ->where('source_type', DentalAnamnesis::class)
            ->where('is_active', true)
            ->update(['is_active' => false, 'deactivated_by' => $user->getKey(), 'deactivated_at' => now()]);

        $answers = $anamnesis->answers ?? [];

        foreach ($anamnesis->questionnaire_snapshot ?? [] as $question) {
            $type = $question['alert_type'] ?? null;
            $value = $answers[$question['key']] ?? null;
            $answer = is_array($value) ? ($value['answer'] ?? null) : $value;

            if ($type === null || ! in_array($answer, [true, 1, '1', 'yes', 'sim'], true)) {
                continue;
            }

            $details = is_array($value) ? ($value['details'] ?? null) : null;
            $alert = new PatientClinicalAlert([
                'type' => $type,
                'severity' => $question['severity'] ?? 'attention',
                'title' => $question['label'],
                'description' => filled($details) ? (string) $details : null,
                'source_type' => DentalAnamnesis::class,
                'source_id' => $anamnesis->getKey(),
                'is_active' => true,
            ]);
            $alert->company_id = $company->getKey();
            $alert->client_id = $client->getKey();
            $alert->created_by = $user->getKey();
            $alert->save();
        }
    }

    protected function clientFor(Company $company, DentalAnamnesis $anamnesis): Client
    {
        abort_unless((int) $anamnesis->company_id === (int) $company->getKey(), 404);

        return Client::query()->where('company_id', $company->getKey())->findOrFail($anamnesis->client_id);
    }

    protected function assertDraft(DentalAnamnesis $anamnesis): void
    {
        if ($anamnesis->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Somente anamneses em rascunho podem ser alteradas.']);
        }
    }
}
