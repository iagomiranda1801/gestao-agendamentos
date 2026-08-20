<?php

namespace App\Services\Client;

use App\Models\Client;
use App\Models\Company;
use App\Models\DentalPatientProfile;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Carbon;
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

            if ($company->isDentalClinic()) {
                $profileData = is_array($data['dental_profile'] ?? null) ? $data['dental_profile'] : [];
                $this->ensureDentalProfile($company, $client, $profileData);
                $this->syncDentalRelations($company, $client, $data);
            }

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

            if ($company->isDentalClinic()) {
                $profileData = is_array($data['dental_profile'] ?? null) ? $data['dental_profile'] : [];
                $this->ensureDentalProfile($company, $client, $profileData);
                $this->syncDentalRelations($company, $client, $data);
            }

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
        unset($data['dental_profile'], $data['guardians'], $data['insurances']);
        unset($data['company_id']);

        if (array_key_exists('phone', $data)) {
            $data['phone_normalized'] = PhoneNormalizer::normalize($data['phone']) ?? '';
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public function ensureDentalProfile(Company $company, Client $client, array $data = []): DentalPatientProfile
    {
        $this->ensureBelongsToCompany($company, $client);

        if (! $company->isDentalClinic()) {
            throw ValidationException::withMessages(['company' => 'O perfil odontológico só pode ser criado em clínica odontológica.']);
        }

        unset($data['company_id'], $data['client_id'], $data['record_number']);

        $profile = DentalPatientProfile::query()->firstOrNew([
            'company_id' => $company->getKey(),
            'client_id' => $client->getKey(),
        ]);

        $profile->company_id = $company->getKey();
        $profile->client_id = $client->getKey();

        if (! $profile->exists) {
            $profile->record_number = 'P'.str_pad((string) $client->getKey(), 6, '0', STR_PAD_LEFT);
        }

        $profile->fill($data);
        $profile->save();

        return $profile;
    }

    /** @param array<string, mixed> $data */
    protected function syncDentalRelations(Company $company, Client $client, array $data): void
    {
        $this->validateGuardianRequirement($company, $client, $data);

        if (array_key_exists('guardians', $data) && is_array($data['guardians'])) {
            $client->guardians()->delete();
            foreach ($data['guardians'] as $guardian) {
                if (! is_array($guardian) || blank($guardian['name'] ?? null)) {
                    continue;
                }
                $model = $client->guardians()->make($guardian);
                $model->company_id = $company->getKey();
                $model->save();
            }
        }

        if (array_key_exists('insurances', $data) && is_array($data['insurances'])) {
            $client->insurances()->delete();
            foreach ($data['insurances'] as $insurance) {
                if (! is_array($insurance) || blank($insurance['provider'] ?? null)) {
                    continue;
                }
                $model = $client->insurances()->make($insurance);
                $model->company_id = $company->getKey();
                $model->save();
            }
        }
    }

    /** @param array<string, mixed> $data */
    protected function validateGuardianRequirement(Company $company, Client $client, array $data): void
    {
        if (! $company->dentalClinicSetting()->where('minor_guardian_required', true)->exists()
            || $client->birth_date === null
            || Carbon::parse($client->birth_date)->age >= 18) {
            return;
        }

        $guardians = $data['guardians'] ?? null;
        $hasGuardian = is_array($guardians)
            ? collect($guardians)->contains(fn (mixed $guardian): bool => is_array($guardian) && filled($guardian['name'] ?? null))
            : $client->guardians()->exists();

        if (! $hasGuardian) {
            throw ValidationException::withMessages(['guardians' => 'Cadastre um responsável para o paciente menor de idade.']);
        }
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
