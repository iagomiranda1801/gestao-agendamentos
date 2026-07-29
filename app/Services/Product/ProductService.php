<?php

namespace App\Services\Product;

use App\Models\Company;
use App\Models\MeasurementUnit;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Company $company, array $data): Product
    {
        return DB::transaction(function () use ($company, $data): Product {
            $payload = $this->preparePayload($data);

            $this->validateBusinessRules($company, $payload);

            $product = new Product($payload);
            $product->company()->associate($company);
            $product->save();

            return $product->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, Product $product, array $data): Product
    {
        return DB::transaction(function () use ($company, $product, $data): Product {
            $this->ensureBelongsToCompany($company, $product);

            $payload = $this->preparePayload($data);

            $this->validateBusinessRules($company, $payload, $product);

            $product->fill($payload);
            $product->save();

            return $product->refresh();
        });
    }

    public function changeStatus(Company $company, Product $product, bool $isActive): Product
    {
        $this->ensureBelongsToCompany($company, $product);

        $product->update(['is_active' => $isActive]);

        return $product->refresh();
    }

    public function ensureBelongsToCompany(Company $company, Product $product): void
    {
        if ((int) $product->company_id !== (int) $company->getKey()) {
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

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validateBusinessRules(Company $company, array $payload, ?Product $ignore = null): void
    {
        $this->assertNameIsUniqueInCompany($company, $payload['name'] ?? '', $ignore);
        $this->assertSkuIsUniqueInCompany($company, $payload['sku'] ?? null, $ignore);
        $this->assertBarcodeIsUniqueInCompany($company, $payload['barcode'] ?? null, $ignore);
        $this->assertNonNegativeDecimal($payload['reference_unit_cost'] ?? 0, 'reference_unit_cost', 'O custo unitário de referência não pode ser negativo.');
        $this->assertNonNegativeDecimal($payload['sale_price'] ?? 0, 'sale_price', 'O preço de venda não pode ser negativo.');
        $this->assertNonNegativeDecimal($payload['minimum_stock'] ?? 0, 'minimum_stock', 'O estoque mínimo não pode ser negativo.');
        $this->assertMeasurementUnitIsActive($payload['measurement_unit_id'] ?? null);
    }

    protected function assertBarcodeIsUniqueInCompany(Company $company, ?string $barcode, ?Product $ignore = null): void
    {
        if (blank($barcode)) {
            return;
        }

        $exists = Product::query()
            ->where('company_id', $company->getKey())
            ->where('barcode', $barcode)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'barcode' => 'Já existe um produto com este código de barras nesta empresa.',
            ]);
        }
    }

    protected function assertNameIsUniqueInCompany(Company $company, string $name, ?Product $ignore = null): void
    {
        $exists = Product::query()
            ->where('company_id', $company->getKey())
            ->where('name', $name)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Já existe um produto com este nome nesta empresa.',
            ]);
        }
    }

    protected function assertSkuIsUniqueInCompany(Company $company, ?string $sku, ?Product $ignore = null): void
    {
        if (blank($sku)) {
            return;
        }

        $exists = Product::query()
            ->where('company_id', $company->getKey())
            ->where('sku', $sku)
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'sku' => 'Já existe um produto com este SKU nesta empresa.',
            ]);
        }
    }

    protected function assertMeasurementUnitIsActive(?int $measurementUnitId): void
    {
        if (! $measurementUnitId) {
            return;
        }

        $unit = MeasurementUnit::query()->find($measurementUnitId);

        if (! $unit || ! $unit->is_active) {
            throw ValidationException::withMessages([
                'measurement_unit_id' => 'Selecione uma unidade de medida ativa.',
            ]);
        }
    }

    protected function assertNonNegativeDecimal(mixed $value, string $field, string $message): void
    {
        if (bccomp((string) $value, '0', 6) < 0) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
