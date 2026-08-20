<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['provider', 'plan', 'card_number', 'expires_at', 'holder_name', 'notes', 'is_active'])]
class PatientInsurance extends Model
{
    protected $guarded = ['company_id', 'client_id'];

    protected function casts(): array
    {
        return ['expires_at' => 'date', 'is_active' => 'boolean'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
