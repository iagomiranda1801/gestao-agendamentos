<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['professional_record_scope', 'minor_guardian_required', 'clinical_entry_required_to_complete'])]
class DentalClinicSetting extends Model
{
    protected $guarded = ['company_id'];

    protected function casts(): array
    {
        return ['minor_guardian_required' => 'boolean', 'clinical_entry_required_to_complete' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
