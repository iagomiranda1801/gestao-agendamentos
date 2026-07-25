<?php

namespace App\Models;

use Database\Factories\FinancialTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'from_financial_account_id',
    'to_financial_account_id',
    'amount',
    'fee_amount',
    'occurred_at',
    'description',
    'reference_key',
    'created_by',
    'reversed_by',
    'reversed_at',
    'reversal_reason',
])]
class FinancialTransfer extends Model
{
    /** @use HasFactory<FinancialTransferFactory> */
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
            'amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'occurred_at' => 'datetime',
            'reversed_at' => 'datetime',
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
    public function fromFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'from_financial_account_id');
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function toFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'to_financial_account_id');
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

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function outboundReferenceKey(): string
    {
        return "financial-transfer:{$this->getKey()}:transfer_out";
    }

    public function inboundReferenceKey(): string
    {
        return "financial-transfer:{$this->getKey()}:transfer_in";
    }

    public function feeReferenceKey(): string
    {
        return "financial-transfer:{$this->getKey()}:fee";
    }
}
