<?php

namespace App\Services\Stock;

use App\Enums\StockDocumentStatus;
use App\Enums\StockDocumentType;
use App\Enums\StockMovementDirection;
use App\Models\Company;
use App\Models\StockDocument;
use App\Models\StockMovement;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockDocumentReversalService
{
    public function __construct(
        protected StockDocumentService $documentService,
        protected StockBalanceService $balanceService,
        protected StockCostCalculator $costCalculator,
    ) {}

    public function reverse(
        Company $company,
        StockDocument $document,
        User $user,
        string $reason,
    ): StockDocument {
        if (blank(trim($reason))) {
            throw ValidationException::withMessages([
                'reversal_reason' => 'Informe o motivo do estorno.',
            ]);
        }

        return DB::transaction(function () use ($company, $document, $user, $reason): StockDocument {
            $lockedDocument = StockDocument::query()
                ->whereKey($document->getKey())
                ->where('company_id', $company->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->documentService->ensureBelongsToCompany($company, $lockedDocument);

            if (! $lockedDocument->isPosted()) {
                throw ValidationException::withMessages([
                    'status' => 'Somente documentos lançados podem ser estornados.',
                ]);
            }

            if ($lockedDocument->isReversed()) {
                throw ValidationException::withMessages([
                    'status' => 'Este documento já foi estornado.',
                ]);
            }

            if ($lockedDocument->reversalDocument()->exists()) {
                throw ValidationException::withMessages([
                    'status' => 'Este documento já possui um estorno registrado.',
                ]);
            }

            $movements = StockMovement::query()
                ->where('stock_document_id', $lockedDocument->getKey())
                ->whereNull('original_movement_id')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get();

            $reversalDocument = StockDocument::query()->forceCreate([
                'company_id' => $company->getKey(),
                'type' => StockDocumentType::Reversal,
                'status' => StockDocumentStatus::Posted,
                'occurred_at' => now(),
                'notes' => $reason,
                'reversal_of_id' => $lockedDocument->getKey(),
                'reversal_reason' => $reason,
                'created_by' => $user->getKey(),
                'posted_by' => $user->getKey(),
                'posted_at' => now(),
                'total_amount' => $lockedDocument->total_amount,
            ]);

            $totalAmount = '0';
            $occurredAt = Carbon::parse($reversalDocument->occurred_at);

            foreach ($movements as $movement) {
                $totalAmount = bcadd(
                    $totalAmount,
                    $this->reverseMovement($company, $reversalDocument, $movement, $user, $occurredAt),
                    6,
                );
            }

            $reversalDocument->forceFill(['total_amount' => $totalAmount])->save();

            $lockedDocument->forceFill([
                'status' => StockDocumentStatus::Reversed,
                'reversed_by' => $user->getKey(),
                'reversed_at' => now(),
                'reversal_reason' => $reason,
            ])->save();

            return $reversalDocument->refresh()->load('reversalOf');
        });
    }

    protected function reverseMovement(
        Company $company,
        StockDocument $reversalDocument,
        StockMovement $original,
        User $user,
        Carbon $occurredAt,
    ): string {
        $product = $original->product;
        $balance = $this->balanceService->lockBalance($company, $product);

        $qtyBefore = (string) $balance->quantity_on_hand;
        $avgBefore = (string) $balance->average_unit_cost;
        $quantity = (string) $original->quantity;

        if ($original->direction === StockMovementDirection::Inbound) {
            if (bccomp($qtyBefore, $quantity, 4) < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Estorno impossível: saldo insuficiente para reverter {$product->name}.",
                ]);
            }

            $reversed = $this->costCalculator->reverseInbound(
                $qtyBefore,
                $avgBefore,
                $quantity,
                (string) $original->unit_cost,
            );

            $qtyAfter = $reversed['quantity'];
            $avgAfter = $reversed['average'];
            $direction = StockMovementDirection::Outbound;
            $unitCost = (string) $original->unit_cost;
        } else {
            $reversed = $this->costCalculator->reverseOutbound(
                $qtyBefore,
                $avgBefore,
                $quantity,
                (string) $original->unit_cost,
            );

            $qtyAfter = $reversed['quantity'];
            $avgAfter = $reversed['average'];
            $direction = StockMovementDirection::Inbound;
            $unitCost = (string) $original->unit_cost;
        }

        $totalCost = $this->costCalculator->calculateLineTotal($quantity, $unitCost);

        StockMovement::query()->create([
            'company_id' => $company->getKey(),
            'product_id' => $product->getKey(),
            'stock_document_id' => $reversalDocument->getKey(),
            'stock_document_item_id' => $original->stock_document_item_id,
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
            'original_movement_id' => $original->getKey(),
            'notes' => 'Estorno do movimento #'.$original->getKey(),
        ]);

        $this->balanceService->updateBalance($balance, $qtyAfter, $avgAfter, $occurredAt);

        return $totalCost;
    }
}
