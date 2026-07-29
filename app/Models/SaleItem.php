<?php

namespace App\Models;

use App\Enums\SaleItemType;
use Database\Factories\SaleItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'item_type',
    'product_id',
    'service_id',
    'attendance_id',
    'name_snapshot',
    'quantity',
    'unit_price_snapshot',
    'unit_cost_snapshot',
    'discount_amount',
    'line_total',
    'tracks_stock_snapshot',
    'notes',
])]
class SaleItem extends Model
{
    /** @use HasFactory<SaleItemFactory> */
    use HasFactory;

    protected $guarded = ['company_id', 'sale_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => SaleItemType::class,
            'quantity' => 'decimal:4',
            'unit_price_snapshot' => 'decimal:2',
            'unit_cost_snapshot' => 'decimal:6',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'tracks_stock_snapshot' => 'boolean',
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
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }
}
