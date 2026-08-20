<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'service_id', 'professional_id', 'appointment_id', 'attendance_id', 'clinical_entry_id', 'description',
    'tooth', 'surfaces', 'quantity', 'unit_price', 'discount_amount', 'total_amount', 'priority', 'status', 'sort_order',
])]
class DentalTreatmentPlanItem extends Model
{
    protected $guarded = ['company_id', 'treatment_plan_id'];

    protected function casts(): array
    {
        return ['surfaces' => 'array', 'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2', 'total_amount' => 'decimal:2'];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(DentalTreatmentPlan::class, 'treatment_plan_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
