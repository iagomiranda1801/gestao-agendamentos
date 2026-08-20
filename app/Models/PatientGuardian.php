<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'document', 'birth_date', 'relationship', 'phone', 'email', 'is_legal_guardian', 'is_financial_guardian'])]
class PatientGuardian extends Model
{
    protected $guarded = ['company_id', 'client_id'];

    protected function casts(): array
    {
        return ['birth_date' => 'date', 'is_legal_guardian' => 'boolean', 'is_financial_guardian' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
