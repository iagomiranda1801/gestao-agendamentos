<?php

namespace App\Models;

use App\Enums\PayablePaymentStatus;
use App\Enums\PaymentMethod;
use Database\Factories\PayablePaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'payable_id',
    'payable_installment_id',
    'financial_account_id',
    'method',
    'status',
    'settled_principal_amount',
    'interest_amount',
    'penalty_amount',
    'fee_amount',
    'discount_amount',
    'cash_outflow_amount',
    'paid_at',
    'reference',
    'notes',
    'created_by',
    'cancelled_by',
    'cancelled_at',
    'cancellation_reason',
])]
class PayablePayment extends Model
{
    /** @use HasFactory<PayablePaymentFactory> */
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
            'method' => PaymentMethod::class,
            'status' => PayablePaymentStatus::class,
            'settled_principal_amount' => 'decimal:2',
            'interest_amount' => 'decimal:2',
            'penalty_amount' => 'decimal:2',
            'fee_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'cash_outflow_amount' => 'decimal:2',
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

    /**
     * @return BelongsTo<Payable, $this>
     */
    public function payable(): BelongsTo
    {
        return $this->belongsTo(Payable::class);
    }

    /**
     * @return BelongsTo<PayableInstallment, $this>
     */
    public function installment(): BelongsTo
    {
        return $this->belongsTo(PayableInstallment::class, 'payable_installment_id');
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
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
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function isConfirmed(): bool
    {
        return $this->status === PayablePaymentStatus::Confirmed;
    }

    public function ledgerReferenceKey(): string
    {
        return "payable-payment:{$this->getKey()}:outbound";
    }
}
