<?php

namespace App\Services\PublicBooking;

use App\Models\Client;
use App\Models\Company;
use App\Support\Cpf;
use App\Support\PhoneNormalizer;
use Illuminate\Validation\ValidationException;

class PublicClientLookupService
{
    /**
     * @return array{found: bool, name?: string, phone?: string, email?: string|null, message: string}
     */
    public function lookupByPhone(Company $company, string $phone): array
    {
        $normalized = PhoneNormalizer::normalize($phone);

        if ($normalized === null || strlen($normalized) < 10) {
            throw ValidationException::withMessages([
                'clientPhone' => 'Informe um telefone válido.',
            ]);
        }

        $client = Client::query()
            ->where('company_id', $company->getKey())
            ->where('phone_normalized', $normalized)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->first();

        if ($client === null) {
            return [
                'found' => false,
                'message' => 'Telefone não cadastrado. Preencha seus dados normalmente.',
            ];
        }

        return [
            'found' => true,
            'name' => $client->name,
            'phone' => $client->phone,
            'email' => $client->email,
            'message' => 'Dados encontrados. Confira e continue.',
        ];
    }

    /**
     * @return array{found: bool, name?: string, phone?: string, email?: string|null, message: string}
     */
    public function lookupByCpf(Company $company, string $cpf): array
    {
        $normalized = Cpf::normalize($cpf);

        if ($normalized === null || ! Cpf::isValid($normalized)) {
            throw ValidationException::withMessages([
                'clientDocument' => 'Informe um CPF válido.',
            ]);
        }

        $client = Client::query()
            ->where('company_id', $company->getKey())
            ->where('document', $normalized)
            ->where('is_active', true)
            ->first();

        if ($client === null) {
            return [
                'found' => false,
                'message' => 'CPF não cadastrado. Preencha seus dados normalmente.',
            ];
        }

        return [
            'found' => true,
            'name' => $client->name,
            'phone' => $client->phone,
            'email' => $client->email,
            'message' => 'Dados encontrados. Confira e continue.',
        ];
    }
}
