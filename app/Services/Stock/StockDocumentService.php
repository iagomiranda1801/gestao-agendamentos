<?php

namespace App\Services\Stock;

use App\Enums\StockDocumentStatus;
use App\Enums\StockDocumentType;
use App\Models\Company;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockDocumentService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function createDraft(
        Company $company,
        StockDocumentType $type,
        array $data,
        array $items,
        User $user,
    ): StockDocument {
        return DB::transaction(function () use ($company, $type, $data, $items, $user): StockDocument {
            if ($type === StockDocumentType::Reversal) {
                throw ValidationException::withMessages([
                    'type' => 'Documentos de estorno não podem ser criados manualmente.',
                ]);
            }

            $payload = $this->prepareDocumentPayload($data);

            $this->validateDraftHeader($company, $type, $payload);

            $document = new StockDocument([
                ...$payload,
                'type' => $type,
                'status' => StockDocumentStatus::Draft,
            ]);

            $document->company()->associate($company);
            $document->creator()->associate($user);
            $document->save();

            $this->syncItems($company, $document, $items);

            return $document->refresh()->load('items.product.measurementUnit');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array<string, mixed>>  $items
     */
    public function updateDraft(
        Company $company,
        StockDocument $document,
        array $data,
        array $items,
    ): StockDocument {
        return DB::transaction(function () use ($company, $document, $data, $items): StockDocument {
            $this->ensureBelongsToCompany($company, $document);
            $this->ensureIsDraft($document);

            $payload = $this->prepareDocumentPayload($data);

            $this->validateDraftHeader($company, $document->type, $payload, $document);

            $document->fill($payload);
            $document->save();

            $this->syncItems($company, $document, $items);

            return $document->refresh()->load('items.product.measurementUnit');
        });
    }

    public function ensureBelongsToCompany(Company $company, StockDocument $document): void
    {
        if ((int) $document->company_id !== (int) $company->getKey()) {
            abort(404);
        }
    }

    public function ensureIsDraft(StockDocument $document): void
    {
        if (! $document->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'Somente documentos em rascunho podem ser editados.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function prepareDocumentPayload(array $data): array
    {
        unset($data['company_id'], $data['type'], $data['status'], $data['total_amount']);

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validateDraftHeader(
        Company $company,
        StockDocumentType $type,
        array $payload,
        ?StockDocument $ignore = null,
    ): void {
        if (filled($payload['supplier_id'] ?? null)) {
            $supplier = Supplier::query()
                ->whereKey($payload['supplier_id'])
                ->where('company_id', $company->getKey())
                ->first();

            if (! $supplier) {
                throw ValidationException::withMessages([
                    'supplier_id' => 'Fornecedor inválido para esta empresa.',
                ]);
            }
        }

        if (
            in_array($type, [StockDocumentType::Purchase, StockDocumentType::ServiceConsumption], true)
            && filled($payload['reference_key'] ?? null)
        ) {
            $exists = StockDocument::query()
                ->where('company_id', $company->getKey())
                ->where('reference_key', $payload['reference_key'])
                ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'reference_key' => 'Já existe um documento com esta referência.',
                ]);
            }
        }

        if ($type->requiresJustification() && blank($payload['notes'] ?? null)) {
            throw ValidationException::withMessages([
                'notes' => 'Informe uma justificativa para este tipo de movimentação.',
            ]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function syncItems(Company $company, StockDocument $document, array $items): void
    {
        if ($items === []) {
            throw ValidationException::withMessages([
                'items' => 'Adicione ao menos um produto ao documento.',
            ]);
        }

        $validatedItems = $this->validateItems($company, $document->type, $items);

        $document->items()->delete();

        foreach ($validatedItems as $itemData) {
            $item = new StockDocumentItem($itemData);
            $item->company()->associate($company);
            $item->document()->associate($document);
            $item->save();
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    protected function validateItems(Company $company, StockDocumentType $type, array $items): array
    {
        $validated = [];
        $productIds = [];

        foreach ($items as $index => $item) {
            $product = $this->resolveProduct($company, (int) ($item['product_id'] ?? 0));

            if (in_array($product->getKey(), $productIds, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'O mesmo produto não pode ser adicionado mais de uma vez.',
                ]);
            }

            $productIds[] = $product->getKey();

            $validated[] = $this->validateItemForType($type, $product, $item, $index);
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function validateItemForType(
        StockDocumentType $type,
        Product $product,
        array $item,
        int $index,
    ): array {
        return match ($type) {
            StockDocumentType::OpeningBalance,
            StockDocumentType::Purchase,
            StockDocumentType::ManualEntry => $this->validateInboundItem($item, $index, requireUnitCost: true),
            StockDocumentType::ManualExit,
            StockDocumentType::Loss,
            StockDocumentType::ServiceConsumption => $this->validateOutboundItem($item, $index),
            StockDocumentType::InventoryCount => $this->validateInventoryItem($item, $index),
            default => throw ValidationException::withMessages([
                'type' => 'Tipo de documento inválido.',
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function validateInboundItem(array $item, int $index, bool $requireUnitCost = true): array
    {
        $quantity = $this->normalizePositiveQuantity($item['quantity'] ?? null, $index);

        $unitCost = null;

        if ($requireUnitCost) {
            $unitCost = $this->normalizeNonNegativeCost($item['unit_cost'] ?? null, $index);
        }

        return [
            'product_id' => (int) $item['product_id'],
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'notes' => $item['notes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function validateOutboundItem(array $item, int $index): array
    {
        $quantity = $this->normalizePositiveQuantity($item['quantity'] ?? null, $index);

        return [
            'product_id' => (int) $item['product_id'],
            'quantity' => $quantity,
            'notes' => $item['notes'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function validateInventoryItem(array $item, int $index): array
    {
        $counted = $item['counted_quantity'] ?? null;

        if ($counted === null || bccomp((string) $counted, '0', 4) < 0) {
            throw ValidationException::withMessages([
                "items.{$index}.counted_quantity" => 'Informe uma quantidade contada válida.',
            ]);
        }

        return [
            'product_id' => (int) $item['product_id'],
            'counted_quantity' => (string) $counted,
            'notes' => $item['notes'] ?? null,
        ];
    }

    protected function resolveProduct(Company $company, int $productId): Product
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

        if (! $product->tracks_stock) {
            throw ValidationException::withMessages([
                'product_id' => 'Este produto não possui controle de estoque.',
            ]);
        }

        return $product;
    }

    protected function normalizePositiveQuantity(mixed $quantity, int $index): string
    {
        if ($quantity === null || bccomp((string) $quantity, '0', 4) <= 0) {
            throw ValidationException::withMessages([
                "items.{$index}.quantity" => 'A quantidade deve ser maior que zero.',
            ]);
        }

        return (string) $quantity;
    }

    protected function normalizeNonNegativeCost(mixed $cost, int $index): string
    {
        if ($cost === null || bccomp((string) $cost, '0', 6) < 0) {
            throw ValidationException::withMessages([
                "items.{$index}.unit_cost" => 'O custo unitário deve ser maior ou igual a zero.',
            ]);
        }

        return (string) $cost;
    }

    /**
     * @return Collection<int, StockDocumentItem>
     */
    public function getSortedItems(StockDocument $document): Collection
    {
        return $document->items()
            ->with('product')
            ->orderBy('product_id')
            ->get();
    }
}
