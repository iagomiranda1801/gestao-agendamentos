<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DentalPatientMigrationService
{
    public function __construct(protected ClientService $clients) {}

    /** @return array{analyzed: int, converted: int, already_prepared: int} */
    public function prepareExistingClients(Company $company): array
    {
        if (! $company->isDentalClinic()) {
            throw ValidationException::withMessages(['company' => 'Esta empresa não utiliza o perfil de clínica odontológica.']);
        }

        $result = ['analyzed' => 0, 'converted' => 0, 'already_prepared' => 0];

        Client::query()
            ->where('company_id', $company->getKey())
            ->orderBy('id')
            ->chunkById(100, function ($clients) use ($company, &$result): void {
                DB::transaction(function () use ($clients, $company, &$result): void {
                    foreach ($clients as $client) {
                        $result['analyzed']++;

                        if ($client->dentalProfile()->exists()) {
                            $result['already_prepared']++;

                            continue;
                        }

                        $this->clients->ensureDentalProfile($company, $client);
                        $result['converted']++;
                    }
                });
            });

        return $result;
    }
}
