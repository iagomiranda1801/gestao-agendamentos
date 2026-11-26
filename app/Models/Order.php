<?php

namespace App\Models;

use App\Enums\OrderChannel;
use App\Enums\OrderFulfillment;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Support\Money;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
    'channel',
    'payment_method',
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
            'channel' => OrderChannel::class,
            'payment_method' => PaymentMethod::class,
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

    /**
     * @return BelongsTo<DiningTable, $this>
     */
    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'table_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /**
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [
            OrderStatus::Completed->value,
            OrderStatus::Cancelled->value,
        ]);
    }

    public function isFloorOrder(): bool
    {
        return $this->channel === OrderChannel::Floor;
    }

    public function isOpen(): bool
    {
        return $this->status !== null && ! $this->status->isTerminal();
    }

    public function diningTableLabel(): ?string
    {
        if ($this->table_id === null) {
            return null;
        }

        $table = $this->diningTable;

        return $table?->displayLabel() ?? ('Mesa '.$this->table_id);
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

    public function shortDeliveryAddress(): ?string
    {
        $parts = array_filter([
            $this->delivery_address,
            $this->delivery_neighborhood,
        ], fn (?string $part): bool => filled($part));

        if ($parts !== []) {
            return implode(', ', $parts);
        }

        return $this->formattedDeliveryAddress();
    }

    public function kitchenFulfillmentDetail(): ?string
    {
        return match ($this->fulfillment) {
            OrderFulfillment::Delivery => $this->shortDeliveryAddress(),
            OrderFulfillment::DineIn => $this->diningTableLabel(),
            default => null,
        };
    }

    public function nextStatus(): ?OrderStatus
    {
        $next = $this->status?->next($this->fulfillment);

        if ($this->isFloorOrder() && $next === OrderStatus::Completed) {
            return null;
        }

        return $next;
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
