<?php

namespace App\Models;

use App\Enums\ExpenseCategoryType;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'parent_id',
    'name',
    'code',
    'type',
    'description',
    'affects_managerial_result',
    'is_system',
    'is_active',
    'sort_order',
])]
class ExpenseCategory extends Model
{
    /** @use HasFactory<ExpenseCategoryFactory> */
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
            'type' => ExpenseCategoryType::class,
            'affects_managerial_result' => 'boolean',
            'is_system' => 'boolean',
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
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ExpenseCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Payable, $this>
     */
    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    /**
     * @return HasMany<RecurringExpenseTemplate, $this>
     */
    public function recurringExpenseTemplates(): HasMany
    {
        return $this->hasMany(RecurringExpenseTemplate::class);
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }
}
