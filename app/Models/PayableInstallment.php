<?php

namespace App\Models;

use App\Enums\PayableStatus;
use Database\Factories\PayableInstallmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'payable_id',
    'installment_number',
    'due_date',
    'original_amount',
    'settled_principal_amount',
    'outstanding_amount',
    'status',
    'settled_at',
    'notes',
])]
class PayableInstallment extends Model
{
    /** @use HasFactory<PayableInstallmentFactory> */
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
            'due_date' => 'date',
            'original_amount' => 'decimal:2',
            'settled_principal_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'status' => PayableStatus::class,
            'settled_at' => 'datetime',
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
     * @return BelongsTo<Payable, $this>
     */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    /**
     * @return HasMany<PayablePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PayablePayment::class);
    }

    public function isSettled(): bool
    {
        return $this->status === PayableStatus::Paid;
    }
}
