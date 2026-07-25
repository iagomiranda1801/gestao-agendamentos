<?php

namespace App\Models;

use App\Enums\CashSessionStatus;
use Database\Factories\CashSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'cash_register_id',
    'status',
    'opened_by',
    'opened_at',
    'expected_opening_amount',
    'counted_opening_amount',
    'opening_difference_amount',
    'closed_by',
    'closed_at',
    'expected_closing_amount',
    'counted_closing_amount',
    'closing_difference_amount',
    'opening_notes',
    'closing_notes',
])]
class CashSession extends Model
{
    /** @use HasFactory<CashSessionFactory> */
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
            'status' => CashSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'expected_opening_amount' => 'decimal:2',
            'counted_opening_amount' => 'decimal:2',
            'opening_difference_amount' => 'decimal:2',
            'expected_closing_amount' => 'decimal:2',
            'counted_closing_amount' => 'decimal:2',
            'closing_difference_amount' => 'decimal:2',
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
     * @return BelongsTo<CashRegister, $this>
     */
    public function cashRegister(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return HasMany<CashSessionAdjustment, $this>
     */
    public function adjustments(): HasMany
    {
        return $this->hasMany(CashSessionAdjustment::class);
    }

    /**
     * @return HasMany<FinancialTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    public function isOpen(): bool
    {
        return $this->status === CashSessionStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === CashSessionStatus::Closed;
    }
}
