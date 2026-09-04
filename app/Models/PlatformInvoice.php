<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\PlatformInvoiceStatus;
use Database\Factories\PlatformInvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'number',
    'status',
    'billing_interval',
    'amount_cents',
    'items',
    'period_start',
    'period_end',
    'due_at',
    'paid_at',
    'cancelled_at',
    'notes',
])]
class PlatformInvoice extends Model
{
    /** @use HasFactory<PlatformInvoiceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PlatformInvoiceStatus::class,
            'billing_interval' => BillingInterval::class,
            'amount_cents' => 'integer',
            'items' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'due_at' => 'datetime',
            'paid_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isOutstanding(): bool
    {
        return $this->status->isOutstanding();
    }
}
