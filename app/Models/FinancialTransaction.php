<?php

namespace App\Models;

use App\Enums\FinancialTransactionDirection;
use App\Enums\FinancialTransactionType;
use Database\Factories\FinancialTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FinancialTransaction extends Model
{
    /** @use HasFactory<FinancialTransactionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (FinancialTransaction $transaction): bool {
            $allowed = ['reversed_at', 'reversed_by', 'reversal_reason'];
            $dirty = array_keys($transaction->getDirty());

            if ($dirty !== [] && array_diff($dirty, $allowed) !== []) {
                throw new \RuntimeException('Financial transactions are immutable.');
            }

            return true;
        });

        static::deleting(function (): bool {
            throw new \RuntimeException('Financial transactions cannot be deleted.');
        });
    }

    /**
     * @var list<string>
     */
    protected $guarded = [
        'id',
        'company_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'direction' => FinancialTransactionDirection::class,
            'type' => FinancialTransactionType::class,
            'amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'reversed_at' => 'datetime',
            'metadata' => 'array',
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
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    /**
     * @return BelongsTo<FinancialTransaction, $this>
     */
    public function originalTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'original_transaction_id');
    }

    /**
     * @return HasOne<FinancialTransaction, $this>
     */
    public function reversalTransaction(): HasOne
    {
        return $this->hasOne(self::class, 'original_transaction_id');
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
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /**
     * @return BelongsTo<CashSession, $this>
     */
    public function cashSession(): BelongsTo
    {
        return $this->belongsTo(CashSession::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }
}
