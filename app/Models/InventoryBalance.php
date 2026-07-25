<?php

namespace App\Models;

use Database\Factories\InventoryBalanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([])]
class InventoryBalance extends Model
{
    /** @use HasFactory<InventoryBalanceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
            'average_unit_cost' => 'decimal:6',
            'last_movement_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function totalInventoryValue(): string
    {
        return bcmul(
            (string) $this->quantity_on_hand,
            (string) $this->average_unit_cost,
            6,
        );
    }

    public function isOutOfStock(): bool
    {
        return bccomp((string) $this->quantity_on_hand, '0', 4) <= 0;
    }

    public function isBelowMinimumStock(): bool
    {
        $this->loadMissing('product');

        if (! $this->product->tracks_stock) {
            return false;
        }

        $minimum = (string) $this->product->minimum_stock;

        if (bccomp($minimum, '0', 4) <= 0) {
            return $this->isOutOfStock();
        }

        return bccomp((string) $this->quantity_on_hand, $minimum, 4) <= 0;
    }
}
