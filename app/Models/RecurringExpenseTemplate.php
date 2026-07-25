<?php

namespace App\Models;

use App\Enums\RecurrenceFrequency;
use Database\Factories\RecurringExpenseTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'supplier_id',
    'expense_category_id',
    'default_financial_account_id',
    'description',
    'frequency',
    'amount',
    'starts_on',
    'ends_on',
    'day_of_month',
    'weekday',
    'next_generation_date',
    'generate_days_in_advance',
    'auto_generate',
    'notes',
    'is_active',
    'created_by',
])]
class RecurringExpenseTemplate extends Model
{
    /** @use HasFactory<RecurringExpenseTemplateFactory> */
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
            'frequency' => RecurrenceFrequency::class,
            'amount' => 'decimal:2',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'next_generation_date' => 'date',
            'auto_generate' => 'boolean',
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
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function defaultFinancialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class, 'default_financial_account_id');
    }

    /**
     * @return HasMany<Payable, $this>
     */
    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
