<?php

namespace App\Models;

use App\Enums\FinancialAccountType;
use Database\Factories\FinancialAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'name',
    'type',
    'bank_name',
    'branch',
    'account_number',
    'pix_key',
    'description',
    'allow_negative_balance',
    'is_default_receipt_account',
    'is_default_expense_account',
    'is_active',
    'sort_order',
])]
class FinancialAccount extends Model
{
    /** @use HasFactory<FinancialAccountFactory> */
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
            'type' => FinancialAccountType::class,
            'allow_negative_balance' => 'boolean',
            'is_default_receipt_account' => 'boolean',
            'is_default_expense_account' => 'boolean',
            'is_active' => 'boolean',
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
     * @return HasOne<FinancialAccountBalance, $this>
     */
    public function balance(): HasOne
    {
        return $this->hasOne(FinancialAccountBalance::class);
    }

    /**
     * @return HasMany<FinancialTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(FinancialTransaction::class);
    }

    /**
     * @return HasMany<CashRegister, $this>
     */
    public function cashRegisters(): HasMany
    {
        return $this->hasMany(CashRegister::class);
    }

    public function getCurrentBalance(): string
    {
        $this->loadMissing('balance');

        return (string) ($this->balance?->current_balance ?? '0.00');
    }

    public function canDebit(string $amount): bool
    {
        if ($this->allow_negative_balance) {
            return bccomp($amount, '0', 2) > 0;
        }

        return bccomp($this->getCurrentBalance(), $amount, 2) >= 0;
    }

    public function isCashAccount(): bool
    {
        return $this->type === FinancialAccountType::Cash;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
