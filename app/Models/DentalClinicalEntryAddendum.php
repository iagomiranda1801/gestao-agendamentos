<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['reason', 'content', 'recorded_at'])]
class DentalClinicalEntryAddendum extends Model
{
    protected $table = 'dental_clinical_entry_addenda';

    protected $guarded = ['company_id', 'clinical_entry_id', 'author_id'];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime'];
    }

    public function clinicalEntry(): BelongsTo
    {
        return $this->belongsTo(DentalClinicalEntry::class, 'clinical_entry_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
