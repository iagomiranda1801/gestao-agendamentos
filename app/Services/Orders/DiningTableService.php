<?php

namespace App\Services\Orders;

use App\Models\Company;
use App\Models\DiningTable;
use App\Support\PublicBookingTextSanitizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DiningTableService
{
    public function ensureBelongsToCompany(Company $company, DiningTable $table): void
    {
        if ((int) $table->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): DiningTable
    {
        return DB::transaction(function () use ($company, $data): DiningTable {
            $payload = $this->preparePayload($data);
            $this->assertUniqueNumber($company, $payload['number']);

            $table = new DiningTable($payload);
            $table->company()->associate($company);
            $table->save();

            return $table->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, DiningTable $table, array $data): DiningTable
    {
        return DB::transaction(function () use ($company, $table, $data): DiningTable {
            $this->ensureBelongsToCompany($company, $table);

            $payload = $this->preparePayload($data, $table);

            if (array_key_exists('number', $payload)) {
                $this->assertUniqueNumber($company, $payload['number'], $table);
            }

            if (array_key_exists('is_active', $payload) && $payload['is_active'] === false) {
                $this->assertCanDeactivate($table);
            }

            $table->fill($payload);
            $table->save();

            return $table->refresh();
        });
    }

    public function deactivate(Company $company, DiningTable $table): DiningTable
    {
        return $this->update($company, $table, ['is_active' => false]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{number?: int, name?: string|null, sort_order?: int, is_active?: bool}
     */
    protected function preparePayload(array $data, ?DiningTable $table = null): array
    {
        unset($data['company_id']);

        $payload = [];

        if (array_key_exists('number', $data) || $table === null) {
            $number = (int) ($data['number'] ?? 0);

            if ($number < 1 || $number > 999) {
                throw ValidationException::withMessages([
                    'number' => 'Informe um número de mesa entre 1 e 999.',
                ]);
            }

            $payload['number'] = $number;
        }

        if (array_key_exists('name', $data) || $table === null) {
            $payload['name'] = PublicBookingTextSanitizer::sanitize(
                isset($data['name']) ? (string) $data['name'] : null,
                80,
            );
        }

        if (array_key_exists('sort_order', $data) || $table === null) {
            $sort = (int) ($data['sort_order'] ?? ($payload['number'] ?? $table?->number ?? 0));

            if ($sort < 0) {
                throw ValidationException::withMessages([
                    'sort_order' => 'A ordem não pode ser negativa.',
                ]);
            }

            $payload['sort_order'] = $sort;
        }

        if (array_key_exists('is_active', $data) || $table === null) {
            $payload['is_active'] = filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        }

        return $payload;
    }

    protected function assertUniqueNumber(Company $company, int $number, ?DiningTable $ignore = null): void
    {
        $exists = DiningTable::query()
            ->where('company_id', $company->getKey())
            ->where('number', $number)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'number' => 'Já existe uma mesa com este número nesta empresa.',
            ]);
        }
    }

    protected function assertCanDeactivate(DiningTable $table): void
    {
        if ($table->currentOrder() !== null) {
            throw ValidationException::withMessages([
                'is_active' => 'Feche ou cancele a comanda aberta antes de desativar a mesa.',
            ]);
        }
    }
}
