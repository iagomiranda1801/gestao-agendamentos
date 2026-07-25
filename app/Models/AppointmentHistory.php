<?php

namespace App\Models;

use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentStatus;
use Database\Factories\AppointmentHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'appointment_id',
    'user_id',
    'type',
    'old_status',
    'new_status',
    'old_start_at',
    'new_start_at',
    'old_end_at',
    'new_end_at',
    'description',
    'metadata',
])]
class AppointmentHistory extends Model
{
    /** @use HasFactory<AppointmentHistoryFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AppointmentHistoryType::class,
            'old_status' => AppointmentStatus::class,
            'new_status' => AppointmentStatus::class,
            'old_start_at' => 'datetime',
            'new_start_at' => 'datetime',
            'old_end_at' => 'datetime',
            'new_end_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
