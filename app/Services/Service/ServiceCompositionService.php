<?php

namespace App\Services\Service;

use App\Enums\ProductType;
use App\Models\Company;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceProductConsumption;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCompositionService
{
    public function __construct(
        protected ServiceCatalogService $serviceCatalogService,
    ) {}

    /**
     * @param  array<int, array{product_id: int, quantity: string|float|int, notes?: ?string}>  $items
     * @return Collection<int, ServiceProductConsumption>
     */
    public function sync(Company $company, Service $service, array $items): Collection
    {
        return DB::transaction(function () use ($company, $service, $items): Collection {
            $this->serviceCatalogService->ensureBelongsToCompany($company, $service);

            $validatedItems = $this->validateItems($company, $service, $items);

            $service->consumptions()->delete();

            $created = collect();

            foreach ($validatedItems as $item) {
                $consumption = new ServiceProductConsumption([
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'notes' => $item['notes'] ?? null,
                ]);

                $consumption->company()->associate($company);
                $consumption->service()->associate($service);
                $consumption->save();

                $created->push($consumption);
            }

            return $created;
        });
    }

    public function createConsumption(Company $company, Service $service, array $data): ServiceProductConsumption
    {
        return DB::transaction(function () use ($company, $service, $data): ServiceProductConsumption {
            $this->serviceCatalogService->ensureBelongsToCompany($company, $service);

            $product = $this->resolveConsumableProduct($company, (int) $data['product_id']);

            $this->assertNoDuplicateProduct($service, $product);

            $quantity = $this->normalizeQuantity($data['quantity'] ?? null);

            $consumption = new ServiceProductConsumption([
                'product_id' => $product->getKey(),
                'quantity' => $quantity,
                'notes' => $data['notes'] ?? null,
            ]);

            $consumption->company()->associate($company);
            $consumption->service()->associate($service);
            $consumption->save();

            return $consumption->refresh();
        });
    }

    public function updateConsumption(Company $company, ServiceProductConsumption $consumption, array $data): ServiceProductConsumption
    {
        return DB::transaction(function () use ($company, $consumption, $data): ServiceProductConsumption {
            $this->ensureConsumptionBelongsToCompany($company, $consumption);

            $service = $consumption->service;
            $this->serviceCatalogService->ensureBelongsToCompany($company, $service);

            $productId = (int) ($data['product_id'] ?? $consumption->product_id);
            $product = $this->resolveConsumableProduct($company, $productId);

            if ($product->getKey() !== $consumption->product_id) {
                $this->assertNoDuplicateProduct($service, $product, $consumption);
            }

            $consumption->fill([
                'product_id' => $product->getKey(),
                'quantity' => $this->normalizeQuantity($data['quantity'] ?? $consumption->quantity),
                'notes' => $data['notes'] ?? $consumption->notes,
            ]);

            $consumption->save();

            return $consumption->refresh();
        });
    }

    public function deleteConsumption(Company $company, ServiceProductConsumption $consumption): void
    {
        $this->ensureConsumptionBelongsToCompany($company, $consumption);

        $consumption->delete();
    }

    public function ensureConsumptionBelongsToCompany(Company $company, ServiceProductConsumption $consumption): void
    {
        if ((int) $consumption->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    /**
     * @param  array<int, array{product_id: int, quantity: string|float|int, notes?: ?string}>  $items
     * @return list<array{product_id: int, quantity: string, notes: ?string}>
     */
    protected function validateItems(Company $company, Service $service, array $items): array
    {
        $validated = [];
        $productIds = [];

        foreach ($items as $item) {
            $product = $this->resolveConsumableProduct($company, (int) $item['product_id']);

            if (in_array($product->getKey(), $productIds, true)) {
                throw ValidationException::withMessages([
                    'product_id' => 'O mesmo produto não pode ser adicionado mais de uma vez ao serviço.',
                ]);
            }

            $productIds[] = $product->getKey();

            $validated[] = [
                'product_id' => $product->getKey(),
                'quantity' => $this->normalizeQuantity($item['quantity'] ?? null),
                'notes' => $item['notes'] ?? null,
            ];
        }

        return $validated;
    }

    protected function resolveConsumableProduct(Company $company, int $productId): Product
    {
        $product = Product::query()
            ->whereKey($productId)
            ->where('company_id', $company->getKey())
            ->first();

        if (! $product) {
            throw ValidationException::withMessages([
                'product_id' => 'Produto inválido para esta empresa.',
            ]);
        }

        if (! $product->is_active) {
            throw ValidationException::withMessages([
                'product_id' => 'O produto selecionado está inativo.',
            ]);
        }

        if ($product->type !== ProductType::Consumable) {
            throw ValidationException::withMessages([
                'product_id' => 'Somente materiais de consumo podem compor um serviço.',
            ]);
        }

        return $product;
    }

    protected function assertNoDuplicateProduct(Service $service, Product $product, ?ServiceProductConsumption $ignore = null): void
    {
        $exists = $service->consumptions()
            ->where('product_id', $product->getKey())
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'product_id' => 'Este produto já faz parte da composição do serviço.',
            ]);
        }
    }

    protected function normalizeQuantity(mixed $quantity): string
    {
        if ($quantity === null || bccomp((string) $quantity, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                'quantity' => 'A quantidade deve ser maior que zero.',
            ]);
        }

        return (string) $quantity;
    }
}
