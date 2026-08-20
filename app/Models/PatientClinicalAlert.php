<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'severity', 'title', 'description', 'source_type', 'source_id', 'is_active', 'deactivated_by', 'deactivated_at'])]
class PatientClinicalAlert extends Model
{
    protected $guarded = ['company_id', 'client_id', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'deactivated_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
