<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'professional_id', 'appointment_id', 'attendance_id', 'status', 'occurred_at', 'chief_complaint',
    'clinical_assessment', 'procedure_performed', 'teeth', 'materials_medications', 'anesthetic',
    'complications', 'guidance', 'next_steps', 'recommended_return_at', 'finalized_at',
])]
class DentalClinicalEntry extends Model
{
    protected $guarded = ['company_id', 'client_id', 'author_id'];

    protected function casts(): array
    {
        return ['teeth' => 'array', 'occurred_at' => 'datetime', 'recommended_return_at' => 'date', 'finalized_at' => 'datetime'];
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

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function addenda(): HasMany
    {
        return $this->hasMany(DentalClinicalEntryAddendum::class, 'clinical_entry_id');
    }
}
