<?php

namespace App\Models;

use App\Enums\StockMovementDirection;
use Database\Factories\StockMovementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'company_id',
    'product_id',
    'stock_document_id',
    'stock_document_item_id',
    'direction',
    'quantity',
    'unit_cost',
    'total_cost',
    'quantity_before',
    'quantity_after',
    'average_cost_before',
    'average_cost_after',
    'occurred_at',
    'created_by',
    'original_movement_id',
    'notes',
])]
class StockMovement extends Model
{
    /** @use HasFactory<StockMovementFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => StockMovementDirection::class,
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'total_cost' => 'decimal:6',
            'quantity_before' => 'decimal:4',
            'quantity_after' => 'decimal:4',
            'average_cost_before' => 'decimal:6',
            'average_cost_after' => 'decimal:6',
            'occurred_at' => 'datetime',
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

    /**
     * @return BelongsTo<StockDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    /**
     * @return BelongsTo<StockDocumentItem, $this>
     */
    public function documentItem(): BelongsTo
    {
        return $this->belongsTo(StockDocumentItem::class, 'stock_document_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function originalMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_movement_id');
    }

    /**
     * @return HasOne<StockMovement, $this>
     */
    public function reversalMovement(): HasOne
    {
        return $this->hasOne(self::class, 'original_movement_id');
    }
}
