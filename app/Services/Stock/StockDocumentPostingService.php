<?php

namespace App\Services\Stock;

use App\Enums\StockDocumentStatus;
use App\Enums\StockDocumentType;
use App\Enums\StockMovementDirection;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\StockDocumentItem;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockDocumentPostingService
{
    public function __construct(
        protected StockDocumentService $documentService,
        protected StockBalanceService $balanceService,
        protected StockCostCalculator $costCalculator,
    ) {}

    public function post(Company $company, StockDocument $document, User $user): StockDocument
    {
        return DB::transaction(function () use ($company, $document, $user): StockDocument {
            if (! $company->is_active) {
                throw ValidationException::withMessages([
                    'company' => 'A empresa precisa estar ativa para lançar documentos.',
                ]);
            }

            $lockedDocument = StockDocument::query()
                ->whereKey($document->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->documentService->ensureBelongsToCompany($company, $lockedDocument);

            if (! $lockedDocument->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => 'Este documento já foi lançado ou estornado.',
                ]);
            }

            $items = $this->documentService->getSortedItems($lockedDocument);

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Adicione ao menos um produto ao documento.',
                ]);
            }

            $totalAmount = '0';
            $occurredAt = Carbon::parse($lockedDocument->occurred_at);

            foreach ($items as $item) {
                $lineTotal = $this->processItem($company, $lockedDocument, $item, $user, $occurredAt);

                if ($lineTotal !== null) {
                    $totalAmount = bcadd($totalAmount, $lineTotal, 6);
                }
            }

            $lockedDocument->forceFill([
                'status' => StockDocumentStatus::Posted,
                'total_amount' => $totalAmount,
                'posted_by' => $user->getKey(),
                'posted_at' => now(),
            ])->save();

            return $lockedDocument->refresh()->load([
                'items.product.measurementUnit',
                'supplier',
                'creator',
                'poster',
            ]);
        });
    }

    protected function processItem(
        Company $company,
        StockDocument $document,
        StockDocumentItem $item,
        User $user,
        Carbon $occurredAt,
    ): ?string {
        $product = $this->resolveProduct($company, $item->product_id);

        return match ($document->type) {
            StockDocumentType::OpeningBalance => $this->processOpeningBalance($company, $document, $item, $product, $user, $occurredAt),
            StockDocumentType::Purchase => $this->processInbound($company, $document, $item, $product, $user, $occurredAt, (string) $item->unit_cost),
            StockDocumentType::ManualEntry => $this->processManualEntry($company, $document, $item, $product, $user, $occurredAt),
            StockDocumentType::ManualExit,
            StockDocumentType::Loss,
            StockDocumentType::ServiceConsumption => $this->processOutbound($company, $document, $item, $product, $user, $occurredAt),
            StockDocumentType::InventoryCount => $this->processInventoryCount($company, $document, $item, $product, $user, $occurredAt),
            default => throw ValidationException::withMessages([
                'type' => 'Tipo de documento não pode ser lançado.',
            ]),
        };
    }

    protected function processOpeningBalance(
        Company $company,
        StockDocument $document,
        StockDocumentItem $item,
        Product $product,
        User $user,
        Carbon $occurredAt,
    ): string {
        $quantity = (string) $item->quantity;
        $unitCost = (string) $item->unit_cost;

        if (StockMovement::query()
            ->where('company_id', $company->getKey())
            ->where('product_id', $product->getKey())
            ->where('stock_document_id', '!=', $document->getKey())
            ->exists()) {
            throw ValidationException::withMessages([
                'product_id' => "O produto {$product->name} já possui movimentações anteriores.",
            ]);
        }

        $balance = $this->balanceService->lockBalance($company, $product);

        if (bccomp((string) $balance->quantity_on_hand, '0', 4) !== 0) {
            throw ValidationException::withMessages([
                'product_id' => "O produto {$product->name} já possui saldo diferente de zero.",
            ]);
        }

        if (StockDocument::query()
            ->where('company_id', $company->getKey())
            ->where('type', StockDocumentType::OpeningBalance)
            ->where('status', StockDocumentStatus::Posted)
            ->whereKeyNot($document->getKey())
            ->whereHas('items', fn ($query) => $query->where('product_id', $product->getKey()))
            ->exists()) {
            throw ValidationException::withMessages([
                'product_id' => "O produto {$product->name} já recebeu saldo inicial.",
            ]);
        }

        return $this->applyInboundMovement(
            $company,
            $document,
            $item,
            $product,
            $balance,
            $quantity,
            $unitCost,
            $user,
            $occurredAt,
        );
    }

    protected function processInbound(
        Company $company,
        StockDocument $document,
        StockDocumentItem $item,
        Product $product,
        User $user,
        Carbon $occurredAt,
        string $unitCost,
    ): string {
        $balance = $this->balanceService->lockBalance($company, $product);

        return $this->applyInboundMovement(
            $company,
            $document,
            $item,
            $product,
            $balance,
            (string) $item->quantity,
            $unitCost,
            $user,
            $occurredAt,
        );
    }

    protected function processManualEntry(
        Company $company,
        StockDocument $document,
        StockDocumentItem $item,
        Product $product,
        User $user,
        Carbon $occurredAt,
    ): string {
        $balance = $this->balanceService->lockBalance($company, $product);

        $unitCost = filled($item->unit_cost)
            ? (string) $item->unit_cost
            : $this->resolveFallbackUnitCost($product, $balance);

        return $this->applyInboundMovement(
            $company,
            $document,
            $item,
            $product,
            $balance,
            (string) $item->quantity,
            $unitCost,
            $user,
            $occurredAt,
        );
    }

    protected function processOutbound(
        Company $company,
        StockDocument $document,
        StockDocumentItem $item,
        Product $product,
        User $user,
        Carbon $occurredAt,
    ): string {
        $balance = $this->balanceService->lockBalance($company, $product);
        $quantity = (string) $item->quantity;
        $qtyBefore = (string) $balance->quantity_on_hand;
        $avgBefore = (string) $balance->average_unit_cost;

        if (bccomp($qtyBefore, $quantity, 4) < 0) {
            throw ValidationException::withMessages([
                'quantity' => "Saldo insuficiente para o produto {$product->name}.",
            ]);
        }

        $unitCost = bccomp($avgBefore, '0', 6) > 0
            ? $avgBefore
            : (string) $product->reference_unit_cost;

        $qtyAfter = bcsub($qtyBefore, $quantity, 4);
        $avgAfter = $avgBefore;
        $totalCost = $this->costCalculator->calculateOutboundTotal($quantity, $unitCost);

        $this->createMovement(
            $company,
            $document,
            $item,
            $product,
            StockMovementDirection::Outbound,
            $quantity,
            $unitCost,
            $totalCost,
            $qtyBefore,
            $qtyAfter,
            $avgBefore,
            $avgAfter,
            $user,
            $occurredAt,
        );

        $this->balanceService->updateBalance($balance, $qtyAfter, $avgAfter, $occurredAt);

        return $totalCost;
    }

    protected function processInventoryCount(
        Company $company,
        StockDocument $document,
        StockDocumentItem $item,
        Product $product,
        User $user,
        Carbon $occurredAt,
    ): ?string {
        $balance = $this->balanceService->lockBalance($company, $product);

        $expected = (string) $balance->quantity_on_hand;
        $counted = (string) $item->counted_quantity;
        $delta = bcsub($counted, $expected, 4);

        $item->forceFill([
            'expected_quantity' => $expected,
            'quantity_delta' => $delta,
        ])->save();

        if (bccomp($delta, '0', 4) === 0) {
            return null;
        }

        if (bccomp($delta, '0', 4) > 0) {
            $unitCost = bccomp((string) $balance->average_unit_cost, '0', 6) > 0
                ? (string) $balance->average_unit_cost
                : (string) $product->reference_unit_cost;

            $inboundItem = clone $item;
            $inboundItem->quantity = $delta;
            $inboundItem->unit_cost = $unitCost;

            return $this->applyInboundMovement(
                $company,
                $document,
                $inboundItem,
                $product,
                $balance,
                $delta,
                $unitCost,
                $user,
                $occurredAt,
            );
        }

        $outboundItem = clone $item;
        $outboundItem->quantity = ltrim($delta, '-');

        return $this->processOutbound($company, $document, $outboundItem, $product, $user, $occurredAt);
    }

    protected function applyInboundMovement(
        Company $company,
        StockDocument $document,
        StockDocumentItem $item,
        Product $product,
        InventoryBalance $balance,
        string $quantity,
        string $unitCost,
        User $user,
        Carbon $occurredAt,
    ): string {
        $qtyBefore = (string) $balance->quantity_on_hand;
        $avgBefore = (string) $balance->average_unit_cost;
        $avgAfter = $this->costCalculator->calculateInboundAverage($qtyBefore, $avgBefore, $quantity, $unitCost);
        $qtyAfter = bcadd($qtyBefore, $quantity, 4);
        $totalCost = $this->costCalculator->calculateLineTotal($quantity, $unitCost);

        $this->createMovement(
            $company,
            $document,
            $item,
            $product,
            StockMovementDirection::Inbound,
            $quantity,
            $unitCost,
            $totalCost,
            $qtyBefore,
            $qtyAfter,
            $avgBefore,
            $avgAfter,
            $user,
            $occurredAt,
        );

        $this->balanceService->updateBalance($balance, $qtyAfter, $avgAfter, $occurredAt);

        return $totalCost;
    }

    protected function createMovement(
        Company $company,
        StockDocument $document,
        StockDocumentItem $item,
        Product $product,
        StockMovementDirection $direction,
        string $quantity,
        string $unitCost,
        string $totalCost,
        string $qtyBefore,
        string $qtyAfter,
        string $avgBefore,
        string $avgAfter,
        User $user,
        Carbon $occurredAt,
        ?StockMovement $originalMovement = null,
    ): StockMovement {
        return StockMovement::query()->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'stock_document_id' => $document->getKey(),
            'stock_document_item_id' => $item->getKey(),
            'direction' => $direction,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'quantity_before' => $qtyBefore,
            'quantity_after' => $qtyAfter,
            'average_cost_before' => $avgBefore,
            'average_cost_after' => $avgAfter,
            'occurred_at' => $occurredAt,
            'created_by' => $user->getKey(),
            'original_movement_id' => $originalMovement?->getKey(),
            'notes' => $item->notes,
        ]);
    }

    protected function resolveFallbackUnitCost(Product $product, InventoryBalance $balance): string
    {
        if (bccomp((string) $balance->average_unit_cost, '0', 6) > 0) {
            return (string) $balance->average_unit_cost;
        }

        return (string) $product->reference_unit_cost;
    }

    protected function resolveProduct(Company $company, int $productId): Product
    {
        return Product::query()
            ->whereKey($productId)
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->where('tracks_stock', true)
            ->firstOrFail();
    }
}
