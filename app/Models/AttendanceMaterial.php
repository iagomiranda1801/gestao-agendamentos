<?php

namespace App\Models;

use Database\Factories\AttendanceMaterialFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'attendance_id',
    'product_id',
    'service_product_consumption_id',
    'product_name_snapshot',
    'planned_quantity',
    'quantity',
    'unit_cost_snapshot',
    'total_cost',
    'tracks_stock_snapshot',
    'stock_document_id',
    'stock_document_item_id',
    'stock_movement_id',
    'notes',
])]
class AttendanceMaterial extends Model
{
    /** @use HasFactory<AttendanceMaterialFactory> */
    use HasFactory;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_quantity' => 'decimal:4',
            'quantity' => 'decimal:4',
            'unit_cost_snapshot' => 'decimal:6',
            'total_cost' => 'decimal:6',
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
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ServiceProductConsumption, $this>
     */
    public function serviceProductConsumption(): BelongsTo
    {
        return $this->belongsTo(ServiceProductConsumption::class);
    }

    /**
     * @return BelongsTo<StockDocument, $this>
     */
    public function stockDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class);
    }

    /**
     * @return BelongsTo<StockDocumentItem, $this>
     */
    public function stockDocumentItem(): BelongsTo
    {
        return $this->belongsTo(StockDocumentItem::class);
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }
}
