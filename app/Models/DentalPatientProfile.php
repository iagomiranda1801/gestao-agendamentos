<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'record_number', 'social_name', 'sex', 'postal_code', 'street', 'street_number',
    'address_complement', 'district', 'city', 'state',
])]
class DentalPatientProfile extends Model
{
    protected $guarded = ['company_id', 'client_id'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
