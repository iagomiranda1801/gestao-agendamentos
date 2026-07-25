<?php

namespace App\Services\Stock;

use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class StockBalanceService
{
    public function lockBalance(Company $company, Product $product): InventoryBalance
    {
        $balance = InventoryBalance::query()
            ->where('company_id', $company->getKey())
            ->where('product_id', $product->getKey())
            ->lockForUpdate()
            ->first();

        if ($balance) {
            return $balance;
        }

        try {
            InventoryBalance::query()->forceCreate([
                'company_id' => $company->getKey(),
                'product_id' => $product->getKey(),
                'quantity_on_hand' => 0,
                'average_unit_cost' => 0,
            ]);
        } catch (QueryException) {
            // Concurrent creation handled by unique index.
        }

        return InventoryBalance::query()
            ->where('company_id', $company->getKey())
            ->where('product_id', $product->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function updateBalance(
        InventoryBalance $balance,
        string $quantity,
        string $averageCost,
        Carbon $occurredAt,
    ): InventoryBalance {
        $balance->forceFill([
            'quantity_on_hand' => $quantity,
            'average_unit_cost' => $averageCost,
            'last_movement_at' => $occurredAt,
        ])->save();

        return $balance->refresh();
    }
}
