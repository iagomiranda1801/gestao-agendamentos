<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['professional_id', 'version', 'status', 'finalized_at'])]
class DentalOdontogram extends Model
{
    protected $guarded = ['company_id', 'client_id', 'created_by'];

    protected function casts(): array
    {
        return ['finalized_at' => 'datetime'];
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

    public function entries(): HasMany
    {
        return $this->hasMany(DentalOdontogramEntry::class, 'odontogram_id');
    }
}
