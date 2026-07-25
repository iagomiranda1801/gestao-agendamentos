<?php

namespace App\Services\PublicBooking;

use App\Models\Client;
use App\Models\Company;
use App\Services\Client\ClientService;
use App\Support\Cpf;
use App\Support\PhoneNormalizer;
use App\Support\PublicBookingTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OnlineClientResolver
{
    public function __construct(
        protected ClientService $clientService,
    ) {}

    public function resolve(
        Company $company,
        string $clientName,
        string $clientPhone,
        ?string $clientEmail = null,
        ?string $document = null,
    ): Client {
        $name = PublicBookingTextSanitizer::clientName($clientName);
        $phoneNormalized = PhoneNormalizer::normalize($clientPhone);
        $email = filled($clientEmail) ? trim(strtolower($clientEmail)) : null;
        $normalizedDocument = $this->normalizedDocumentOrNull($document);

        if (blank($name)) {
            throw ValidationException::withMessages([
                'client_name' => 'Informe o nome completo.',
            ]);
        }

        if (blank($phoneNormalized)) {
            throw ValidationException::withMessages([
                'client_phone' => 'Informe um telefone válido.',
            ]);
        }

        return DB::transaction(function () use (
            $company,
            $name,
            $clientPhone,
            $phoneNormalized,
            $email,
            $normalizedDocument,
        ): Client {
            if ($normalizedDocument !== null) {
                $byDocument = Client::query()
                    ->where('company_id', $company->getKey())
                    ->where('document', $normalizedDocument)
                    ->first();

                if ($byDocument !== null) {
                    if (! $byDocument->is_active) {
                        throw ValidationException::withMessages([
                            'client_document' => 'Não foi possível concluir o agendamento. Entre em contato com o estabelecimento.',
                        ]);
                    }

                    $updates = [];

                    if ($byDocument->phone_normalized !== $phoneNormalized) {
                        $updates['phone'] = $clientPhone;
                    }

                    if (blank($byDocument->email) && filled($email)) {
                        $updates['email'] = $email;
                    }

                    if ($updates !== []) {
                        $byDocument->update($updates);
                    }

                    return $byDocument->refresh();
                }
            }

            $normalizedName = $this->normalizeName($name);

            $candidates = Client::query()
                ->where('company_id', $company->getKey())
                ->where('phone_normalized', $phoneNormalized)
                ->get();

            foreach ($candidates as $candidate) {
                if ($this->normalizeName($candidate->name) !== $normalizedName) {
                    continue;
                }

                if (! $candidate->is_active) {
                    throw ValidationException::withMessages([
                        'client_phone' => 'Não foi possível concluir o agendamento. Entre em contato com o estabelecimento.',
                    ]);
                }

                $updates = [];

                if (blank($candidate->email) && filled($email)) {
                    $updates['email'] = $email;
                }

                if (blank($candidate->document) && $normalizedDocument !== null) {
                    $updates['document'] = $normalizedDocument;
                }

                if ($updates !== []) {
                    if (isset($updates['document'])) {
                        $this->clientService->update($company, $candidate, $updates);
                    } else {
                        $candidate->update($updates);
                    }
                }

                return $candidate->refresh();
            }

            return $this->clientService->create($company, [
                'name' => $name,
                'phone' => $clientPhone,
                'email' => $email,
                'document' => $normalizedDocument,
                'is_active' => true,
            ]);
        });
    }

    protected function normalizedDocumentOrNull(?string $document): ?string
    {
        if (blank($document)) {
            return null;
        }

        $normalized = Cpf::normalize($document);

        if ($normalized === null || ! Cpf::isValid($normalized)) {
            throw ValidationException::withMessages([
                'client_document' => 'Informe um CPF válido.',
            ]);
        }

        return $normalized;
    }

    protected function normalizeName(string $name): string
    {
        $normalized = mb_strtolower(trim($name));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);

        if ($ascii !== false) {
            $normalized = $ascii;
        }

        return preg_replace('/[^a-z0-9 ]/', '', $normalized) ?? $normalized;
    }
}
