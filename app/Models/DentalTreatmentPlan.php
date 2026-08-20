<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'professional_id', 'title', 'status', 'plan_date', 'valid_until', 'clinical_notes', 'commercial_notes',
    'subtotal', 'discount_amount', 'total_amount', 'is_primary', 'approved_at', 'approved_by',
])]
class DentalTreatmentPlan extends Model
{
    protected $guarded = ['company_id', 'client_id', 'created_by'];

    protected function casts(): array
    {
        return [
            'plan_date' => 'date', 'valid_until' => 'date', 'approved_at' => 'datetime', 'is_primary' => 'boolean',
            'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(DentalTreatmentPlanItem::class, 'treatment_plan_id')->orderBy('sort_order');
    }
}
