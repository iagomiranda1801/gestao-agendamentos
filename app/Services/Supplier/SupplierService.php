<?php

namespace App\Services\Supplier;

use App\Models\Company;
use App\Models\Supplier;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SupplierService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): Supplier
    {
        return DB::transaction(function () use ($company, $data): Supplier {
            $payload = $this->preparePayload($data);

            $this->validateBusinessRules($company, $payload);

            $supplier = new Supplier($payload);
            $supplier->company()->associate($company);
            $supplier->save();

            return $supplier->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($company, $supplier, $data): Supplier {
            $this->ensureBelongsToCompany($company, $supplier);

            $payload = $this->preparePayload($data);

            $this->validateBusinessRules($company, $payload, $supplier);

            $supplier->fill($payload);
            $supplier->save();

            return $supplier->refresh();
        });
    }

    public function changeStatus(Company $company, Supplier $supplier, bool $isActive): Supplier
    {
        $this->ensureBelongsToCompany($company, $supplier);

        $supplier->update(['is_active' => $isActive]);

        return $supplier->refresh();
    }

    public function ensureBelongsToCompany(Company $company, Supplier $supplier): void
    {
        if ((int) $supplier->company_id !== (int) $company->getKey()) {
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
            $data['phone_normalized'] = PhoneNormalizer::normalize($data['phone']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validateBusinessRules(Company $company, array $payload, ?Supplier $ignore = null): void
    {
        if (blank($payload['name'] ?? null)) {
            throw ValidationException::withMessages([
                'name' => 'O nome é obrigatório.',
            ]);
        }

        if (filled($payload['email'] ?? null) && ! filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => 'Informe um e-mail válido.',
            ]);
        }

        $this->assertDocumentIsUniqueInCompany($company, $payload['document'] ?? null, $ignore);
    }

    protected function assertDocumentIsUniqueInCompany(Company $company, ?string $document, ?Supplier $ignore = null): void
    {
        if (blank($document)) {
            return;
        }

        $exists = Supplier::query()
            ->where('company_id', $company->getKey())
            ->where('document', $document)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'document' => 'Já existe um fornecedor com este documento nesta empresa.',
            ]);
        }
    }
}
