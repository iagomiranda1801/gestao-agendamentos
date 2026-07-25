<?php

namespace App\Models;

use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\AppointmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'client_id',
    'professional_id',
    'service_id',
    'status',
    'origin',
    'reference_key',
    'start_at',
    'end_at',
    'service_name_snapshot',
    'price_snapshot',
    'duration_minutes_snapshot',
    'buffer_before_minutes_snapshot',
    'buffer_after_minutes_snapshot',
    'notes',
    'internal_notes',
    'cancellation_reason',
    'public_confirmation_code',
    'client_name_snapshot',
    'client_phone_snapshot',
    'client_email_snapshot',
    'privacy_accepted_at',
    'terms_accepted_at',
    'public_booked_at',
])]
class Appointment extends Model
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    protected $guarded = [
        'company_id',
        'created_by',
        'updated_by',
        'cancelled_by',
        'confirmed_by',
        'started_by',
        'confirmed_at',
        'started_at',
        'cancelled_at',
        'no_show_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AppointmentStatus::class,
            'origin' => AppointmentOrigin::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'price_snapshot' => 'decimal:2',
            'duration_minutes_snapshot' => 'integer',
            'buffer_before_minutes_snapshot' => 'integer',
            'buffer_after_minutes_snapshot' => 'integer',
            'confirmed_at' => 'datetime',
            'started_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
            'privacy_accepted_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'public_booked_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AppointmentHistory::class);
    }

    /**
     * @return HasMany<AppointmentPublicAccessToken, $this>
     */
    public function publicAccessTokens(): HasMany
    {
        return $this->hasMany(AppointmentPublicAccessToken::class);
    }

    /**
     * @return HasOne<Attendance, $this>
     */
    public function attendance(): HasOne
    {
        return $this->hasOne(Attendance::class);
    }

    public function isBlockingTime(): bool
    {
        return $this->status->blocksTime();
    }

    public function isCancelled(): bool
    {
        return $this->status === AppointmentStatus::Cancelled;
    }

    public function canBeRescheduled(): bool
    {
        return in_array($this->status, [
            AppointmentStatus::Pending,
            AppointmentStatus::Confirmed,
        ], true);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this->status, [
            AppointmentStatus::Pending,
            AppointmentStatus::Confirmed,
        ], true);
    }

    public function canBeConfirmed(): bool
    {
        return $this->status === AppointmentStatus::Pending;
    }

    public function effectiveBlockStartUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->start_at)
            ->subMinutes($this->buffer_before_minutes_snapshot);
    }

    public function effectiveBlockEndUtc(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->end_at)
            ->addMinutes($this->buffer_after_minutes_snapshot);
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', [
            AppointmentStatus::Pending,
            AppointmentStatus::Confirmed,
            AppointmentStatus::InProgress,
            AppointmentStatus::Completed,
        ]);
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeInPeriod(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start);
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('origin', AppointmentOrigin::Online);
    }
}
