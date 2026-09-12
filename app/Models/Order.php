<?php

namespace App\Models;

use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Support\Money;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number',
    'public_code',
    'idempotency_key',
    'status',
    'fulfillment',
    'customer_name',
    'customer_phone',
    'customer_phone_normalized',
    'customer_email',
    'delivery_address',
    'delivery_complement',
    'delivery_neighborhood',
    'delivery_city',
    'subtotal_cents',
    'delivery_fee_cents',
    'total_cents',
    'notes',
    'received_at',
    'preparing_at',
    'ready_at',
    'out_for_delivery_at',
    'completed_at',
    'cancelled_at',
    'cancel_reason',
    'sale_id',
    'table_id',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'status' => OrderStatus::class,
            'fulfillment' => OrderFulfillment::class,
            'subtotal_cents' => 'integer',
            'delivery_fee_cents' => 'integer',
            'total_cents' => 'integer',
            'received_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'out_for_delivery_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'sale_id' => 'integer',
            'table_id' => 'integer',
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
     * @return HasMany<OrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    /**
     * @return BelongsTo<Sale, $this>
     */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function formattedTotal(): string
    {
        return Money::formatCents((int) $this->total_cents);
    }

    public function formattedSubtotal(): string
    {
        return Money::formatCents((int) $this->subtotal_cents);
    }

    public function formattedDeliveryFee(): string
    {
        return Money::formatCents((int) $this->delivery_fee_cents);
    }

    public function formattedDeliveryAddress(): ?string
    {
        $parts = array_filter([
            $this->delivery_address,
            $this->delivery_complement,
            $this->delivery_neighborhood,
            $this->delivery_city,
        ], fn (?string $part): bool => filled($part));

        return $parts === [] ? null : implode(', ', $parts);
    }

    public function nextStatus(): ?OrderStatus
    {
        return $this->status?->next($this->fulfillment);
    }

    public function canAdvance(): bool
    {
        return $this->nextStatus() !== null;
    }

    public function canCancel(): bool
    {
        return $this->status !== null && ! $this->status->isTerminal();
    }

    public function displayNumber(): string
    {
        return '#'.str_pad((string) $this->number, 3, '0', STR_PAD_LEFT);
    }
}
