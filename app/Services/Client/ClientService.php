<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\Company;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): Client
    {
        return DB::transaction(function () use ($company, $data): Client {
            $payload = $this->preparePayload($data);

            $this->assertDocumentIsUniqueInCompany($company, $payload['document'] ?? null);

            $client = new Client($payload);
            $client->company()->associate($company);
            $client->save();

            return $client->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, Client $client, array $data): Client
    {
        return DB::transaction(function () use ($company, $client, $data): Client {
            $this->ensureBelongsToCompany($company, $client);

            $payload = $this->preparePayload($data);

            $this->assertDocumentIsUniqueInCompany(
                $company,
                $payload['document'] ?? null,
                $client,
            );

            $client->fill($payload);
            $client->save();

            return $client->refresh();
        });
    }

    public function changeStatus(Company $company, Client $client, bool $isActive): Client
    {
        $this->ensureBelongsToCompany($company, $client);

        $client->update(['is_active' => $isActive]);

        return $client->refresh();
    }

    public function ensureBelongsToCompany(Company $company, Client $client): void
    {
        if ((int) $client->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        unset($data['company_id']);

        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = PhoneNormalizer::normalize($data['phone']) ?? '';
        }

        return $data;
    }

    protected function assertDocumentIsUniqueInCompany(
        Company $company,
        ?string $document,
        ?Client $ignore = null,
    ): void {
        if (blank($document)) {
            return;
        }

        $exists = Client::query()
            ->where('company_id', $company->getKey())
            ->where('document', $document)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'document' => 'Este documento já está cadastrado para um cliente desta empresa.',
            ]);
        }
    }
}
