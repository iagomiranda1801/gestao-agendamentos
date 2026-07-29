<?php

namespace App\Models;

use App\Enums\StockDocumentStatus;
use App\Enums\StockDocumentType;
use Database\Factories\StockDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'attendance_id',
    'sale_id',
    'supplier_id',
    'type',
    'status',
    'document_number',
    'external_reference',
    'reference_key',
    'occurred_at',
    'notes',
    'total_amount',
    'created_by',
    'posted_by',
    'reversed_by',
    'posted_at',
    'reversed_at',
    'reversal_of_id',
    'reversal_reason',
])]
class StockDocument extends Model
{
    /** @use HasFactory<StockDocumentFactory> */
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
            'type' => StockDocumentType::class,
            'status' => StockDocumentStatus::class,
            'occurred_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
            'total_amount' => 'decimal:6',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /**
     * @return HasMany<StockDocumentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StockDocumentItem::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * @return BelongsTo<StockDocument, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * @return HasOne<StockDocument, $this>
     */
    public function reversalDocument(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function isDraft(): bool
    {
        return $this->status === StockDocumentStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === StockDocumentStatus::Posted;
    }

    public function isReversed(): bool
    {
        return $this->status === StockDocumentStatus::Reversed;
    }

    /**
     * @return HasOne<Payable, $this>
     */
    public function payable(): HasOne
    {
        return $this->hasOne(Payable::class);
    }

    public function calculateDraftTotal(): string
    {
        $this->loadMissing('items');

        $total = '0';

        foreach ($this->items as $item) {
            if ($lineTotal = $item->calculateLineTotal()) {
                $total = bcadd($total, $lineTotal, 6);
            }
        }

        return $total;
    }

    /**
     * @param  Builder<StockDocument>  $query
     * @return Builder<StockDocument>
     */
    public function scopeOfType(Builder $query, StockDocumentType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * @param  Builder<StockDocument>  $query
     * @return Builder<StockDocument>
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', StockDocumentStatus::Draft);
    }

    /**
     * @param  Builder<StockDocument>  $query
     * @return Builder<StockDocument>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', StockDocumentStatus::Posted);
    }
}
