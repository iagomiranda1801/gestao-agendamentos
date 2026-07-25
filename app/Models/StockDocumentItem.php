<?php

namespace App\Models;

use Database\Factories\StockDocumentItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'stock_document_id',
    'product_id',
    'quantity',
    'unit_cost',
    'expected_quantity',
    'counted_quantity',
    'quantity_delta',
    'notes',
])]
class StockDocumentItem extends Model
{
    /** @use HasFactory<StockDocumentItemFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [
        'company_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost' => 'decimal:6',
            'expected_quantity' => 'decimal:4',
            'counted_quantity' => 'decimal:4',
            'quantity_delta' => 'decimal:4',
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
     * @return BelongsTo<StockDocument, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class, 'stock_document_id');
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function calculateLineTotal(): ?string
    {
        if ($this->quantity === null || $this->unit_cost === null) {
            return null;
        }

        return bcmul((string) $this->quantity, (string) $this->unit_cost, 6);
    }
}
