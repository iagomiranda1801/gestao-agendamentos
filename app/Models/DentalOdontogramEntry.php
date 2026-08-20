<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['treatment_plan_item_id', 'clinical_entry_id', 'tooth', 'surfaces', 'condition', 'stage', 'notes'])]
class DentalOdontogramEntry extends Model
{
    protected $guarded = ['company_id', 'odontogram_id'];

    protected function casts(): array
    {
        return ['surfaces' => 'array'];
    }

    public function odontogram(): BelongsTo
    {
        return $this->belongsTo(DentalOdontogram::class, 'odontogram_id');
    }
}
