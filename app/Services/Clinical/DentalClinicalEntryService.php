<?php

namespace App\Services\Clinical;

use App\Enums\CompanyPermission;
use App\Models\Client;
use App\Models\Company;
use App\Models\DentalClinicalEntry;
use App\Models\DentalClinicalEntryAddendum;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DentalClinicalEntryService
{
    public function __construct(protected ClinicalAuthorizationService $authorization, protected ClinicalAuditService $audit) {}

    /** @param array<string, mixed> $data */
    public function createDraft(Company $company, Client $client, Professional $professional, User $user, array $data): DentalClinicalEntry
    {
        $this->authorization->authorize($user, $company, CompanyPermission::WriteClinicalRecords, $client);
        $this->authorization->assertCanActAsProfessional($user, $company, $professional);

        return DB::transaction(function () use ($company, $client, $professional, $user, $data): DentalClinicalEntry {
            unset($data['company_id'], $data['client_id'], $data['author_id'], $data['status'], $data['finalized_at']);
            $entry = new DentalClinicalEntry($data + ['occurred_at' => now(), 'status' => 'draft']);
            $entry->company_id = $company->getKey();
            $entry->client_id = $client->getKey();
            $entry->professional_id = $professional->getKey();
            $entry->author_id = $user->getKey();
            $entry->save();
            $this->audit->record($company, $client, $user, 'clinical_entry.created', $entry);

            return $entry->refresh();
        });
    }

    /** @param array<string, mixed> $data */
    public function updateDraft(Company $company, DentalClinicalEntry $entry, User $user, array $data): DentalClinicalEntry
    {
        $client = $this->clientFor($company, $entry);
        $this->authorization->authorize($user, $company, CompanyPermission::WriteClinicalRecords, $client);
        $this->assertDraftAndAuthor($entry, $user);
        unset($data['company_id'], $data['client_id'], $data['author_id'], $data['status'], $data['finalized_at']);

        return DB::transaction(function () use ($company, $client, $entry, $user, $data): DentalClinicalEntry {
            $entry->update($data);
            $this->audit->record($company, $client, $user, 'clinical_entry.updated', $entry);

            return $entry->refresh();
        });
    }

    public function finalize(Company $company, DentalClinicalEntry $entry, User $user): DentalClinicalEntry
    {
        $client = $this->clientFor($company, $entry);
        $this->authorization->authorize($user, $company, CompanyPermission::FinalizeClinicalRecords, $client);
        $this->assertDraftAndAuthor($entry, $user);

        if (blank($entry->clinical_assessment) && blank($entry->procedure_performed)) {
            throw ValidationException::withMessages(['clinical_assessment' => 'Informe a avaliação clínica ou o procedimento realizado.']);
        }

        return DB::transaction(function () use ($company, $client, $entry, $user): DentalClinicalEntry {
            $entry->update(['status' => 'finalized', 'finalized_at' => now()]);
            $this->audit->record($company, $client, $user, 'clinical_entry.finalized', $entry);

            return $entry->refresh();
        });
    }

    public function addAddendum(Company $company, DentalClinicalEntry $entry, User $user, string $reason, string $content): DentalClinicalEntryAddendum
    {
        $client = $this->clientFor($company, $entry);
        $this->authorization->authorize($user, $company, CompanyPermission::AddClinicalAddenda, $client);

        if ($entry->status !== 'finalized') {
            throw ValidationException::withMessages(['status' => 'Adendos só podem ser adicionados a evoluções finalizadas.']);
        }

        return DB::transaction(function () use ($company, $client, $entry, $user, $reason, $content): DentalClinicalEntryAddendum {
            $addendum = new DentalClinicalEntryAddendum(['reason' => $reason, 'content' => $content, 'recorded_at' => now()]);
            $addendum->company_id = $company->getKey();
            $addendum->clinical_entry_id = $entry->getKey();
            $addendum->author_id = $user->getKey();
            $addendum->save();
            $this->audit->record($company, $client, $user, 'clinical_entry.addendum_created', $addendum, ['clinical_entry_id' => $entry->getKey()]);

            return $addendum->refresh();
        });
    }

    protected function clientFor(Company $company, DentalClinicalEntry $entry): Client
    {
        abort_unless((int) $entry->company_id === (int) $company->getKey(), 404);

        return Client::query()->where('company_id', $company->getKey())->findOrFail($entry->client_id);
    }

    protected function assertDraftAndAuthor(DentalClinicalEntry $entry, User $user): void
    {
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Evoluções finalizadas são imutáveis. Use um adendo.']);
        }
        abort_unless((int) $entry->author_id === (int) $user->getKey(), 403);
    }
}
