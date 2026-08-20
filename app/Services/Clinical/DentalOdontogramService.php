<?php

namespace App\Services\Clinical;

use App\Enums\CompanyPermission;
use App\Models\Client;
use App\Models\Company;
use App\Models\DentalOdontogram;
use App\Models\DentalOdontogramEntry;
use App\Models\Professional;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DentalOdontogramService
{
    private const VALID_TEETH = [
        '11', '12', '13', '14', '15', '16', '17', '18', '21', '22', '23', '24', '25', '26', '27', '28',
        '31', '32', '33', '34', '35', '36', '37', '38', '41', '42', '43', '44', '45', '46', '47', '48',
        '51', '52', '53', '54', '55', '61', '62', '63', '64', '65', '71', '72', '73', '74', '75', '81', '82', '83', '84', '85',
    ];

    public function __construct(protected ClinicalAuthorizationService $authorization, protected ClinicalAuditService $audit) {}

    /** @param list<array<string, mixed>> $entries */
    public function createDraft(Company $company, Client $client, Professional $professional, User $user, array $entries = []): DentalOdontogram
    {
        $this->authorization->authorize($user, $company, CompanyPermission::WriteClinicalRecords, $client);
        $this->authorization->assertCanActAsProfessional($user, $company, $professional);

        return DB::transaction(function () use ($company, $client, $professional, $user, $entries): DentalOdontogram {
            $version = (int) DentalOdontogram::query()->where('company_id', $company->getKey())->where('client_id', $client->getKey())->lockForUpdate()->max('version') + 1;
            $odontogram = new DentalOdontogram(['professional_id' => $professional->getKey(), 'version' => $version, 'status' => 'draft']);
            $odontogram->company_id = $company->getKey();
            $odontogram->client_id = $client->getKey();
            $odontogram->created_by = $user->getKey();
            $odontogram->save();
            $this->replaceEntries($company, $odontogram, $entries);
            $this->audit->record($company, $client, $user, 'odontogram.created', $odontogram, ['version' => $version]);

            return $odontogram->refresh()->load('entries');
        });
    }

    /** @param list<array<string, mixed>> $entries */
    public function replaceEntries(Company $company, DentalOdontogram $odontogram, array $entries): void
    {
        abort_unless((int) $odontogram->company_id === (int) $company->getKey(), 404);
        if ($odontogram->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'Odontogramas finalizados são imutáveis.']);
        }
        $odontogram->entries()->delete();
        foreach ($entries as $data) {
            if (! in_array((string) ($data['tooth'] ?? ''), self::VALID_TEETH, true)) {
                throw ValidationException::withMessages(['tooth' => 'Numeração FDI inválida.']);
            }
            $entry = new DentalOdontogramEntry($data);
            $entry->company_id = $company->getKey();
            $entry->odontogram_id = $odontogram->getKey();
            $entry->save();
        }
    }

    public function finalize(Company $company, DentalOdontogram $odontogram, User $user): DentalOdontogram
    {
        $client = Client::query()->where('company_id', $company->getKey())->findOrFail($odontogram->client_id);
        $this->authorization->authorize($user, $company, CompanyPermission::FinalizeClinicalRecords, $client);
        abort_unless((int) $odontogram->created_by === (int) $user->getKey(), 403);
        if ($odontogram->status !== 'draft') {
            throw ValidationException::withMessages(['status' => 'O odontograma já foi finalizado.']);
        }

        return DB::transaction(function () use ($company, $client, $odontogram, $user): DentalOdontogram {
            $odontogram->update(['status' => 'finalized', 'finalized_at' => now()]);
            $this->audit->record($company, $client, $user, 'odontogram.finalized', $odontogram);

            return $odontogram->refresh();
        });
    }

    /** @param list<array<string, mixed>> $entries */
    public function updateDraft(Company $company, DentalOdontogram $odontogram, User $user, array $entries): DentalOdontogram
    {
        abort_unless((int) $odontogram->company_id === (int) $company->getKey(), 404);
        $client = Client::query()->where('company_id', $company->getKey())->findOrFail($odontogram->client_id);
        $this->authorization->authorize($user, $company, CompanyPermission::WriteClinicalRecords, $client);
        abort_unless((int) $odontogram->created_by === (int) $user->getKey(), 403);

        return DB::transaction(function () use ($company, $client, $odontogram, $user, $entries): DentalOdontogram {
            $this->replaceEntries($company, $odontogram, $entries);
            $this->audit->record($company, $client, $user, 'odontogram.updated', $odontogram);

            return $odontogram->refresh()->load('entries');
        });
    }
}
