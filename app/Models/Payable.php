<?php

namespace App\Models;

use App\Enums\PayableOrigin;
use App\Enums\PayableStatus;
use Database\Factories\PayableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'supplier_id',
    'expense_category_id',
    'stock_document_id',
    'recurring_expense_template_id',
    'attendance_id',
    'professional_id',
    'origin',
    'status',
    'description',
    'document_number',
    'external_reference',
    'reference_key',
    'issue_date',
    'competence_date',
    'total_amount',
    'notes',
    'created_by',
    'cancelled_by',
    'cancelled_at',
    'cancellation_reason',
])]
class Payable extends Model
{
    /** @use HasFactory<PayableFactory> */
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
            'origin' => PayableOrigin::class,
            'status' => PayableStatus::class,
            'issue_date' => 'date',
            'competence_date' => 'date',
            'total_amount' => 'decimal:2',
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
     * @return BelongsTo<StockDocument, $this>
     */
    public function stockDocument(): BelongsTo
    {
        return $this->belongsTo(StockDocument::class);
    }

    /**
     * @return BelongsTo<RecurringExpenseTemplate, $this>
     */
    public function recurringExpenseTemplate(): BelongsTo
    {
        return $this->belongsTo(RecurringExpenseTemplate::class);
    }

    /**
     * @return BelongsTo<Attendance, $this>
     */
    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    /**
     * @return HasMany<PayableInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(PayableInstallment::class);
    }

    /**
     * @return HasMany<PayablePayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(PayablePayment::class);
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

    public function isDraft(): bool
    {
        return $this->status === PayableStatus::Draft;
    }

    public function isCancelled(): bool
    {
        return $this->status === PayableStatus::Cancelled;
    }

    public function isPaid(): bool
    {
        return $this->status === PayableStatus::Paid;
    }
}
