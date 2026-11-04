<?php

namespace App\Models;

use App\Enums\AsaasBillingType;
use App\Enums\AsaasChargeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AsaasCharge extends Model
{
    protected $guarded = [
        'id',
        'company_id',
        'client_id',
        'receivable_id',
        'tattoo_quote_id',
        'payment_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'billing_type' => AsaasBillingType::class,
            'value' => 'decimal:2',
            'net_value' => 'decimal:2',
            'status' => AsaasChargeStatus::class,
            'due_date' => 'date',
            'paid_at' => 'datetime',
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
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Receivable, $this>
     */
    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    /**
     * @return BelongsTo<TattooQuote, $this>
     */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(TattooQuote::class, 'tattoo_quote_id');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function ledgerReferenceKey(): string
    {
        return 'asaas-charge:'.$this->getKey();
    }

    public function feeLedgerReferenceKey(): string
    {
        return 'asaas-charge:'.$this->getKey().':fee';
    }

    public static function depositIsPaid(int $companyId, int $quoteId): bool
    {
        return static::query()
            ->where('company_id', $companyId)
            ->where('tattoo_quote_id', $quoteId)
            ->whereNotNull('settled_at')
            ->exists();
    }
}
