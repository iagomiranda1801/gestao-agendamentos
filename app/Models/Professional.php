<?php

namespace App\Models;

use App\Support\PhoneNormalizer;
use Database\Factories\ProfessionalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'user_id',
    'name',
    'phone',
    'phone_normalized',
    'email',
    'document',
    'specialty',
    'color',
    'notes',
    'is_bookable',
    'sort_order',
    'is_active',
])]
class Professional extends Model
{
    /** @use HasFactory<ProfessionalFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [
        'company_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Professional $professional): void {
            if ($professional->phone === null) {
                $professional->phone_normalized = null;

                return;
            }

            $professional->phone_normalized = PhoneNormalizer::normalize($professional->phone);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_bookable' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  Builder<Professional>  $query
     * @return Builder<Professional>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Professional>  $query
     * @return Builder<Professional>
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * @param  Builder<Professional>  $query
     * @return Builder<Professional>
     */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('is_bookable', true);
    }

    /**
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'professional_service')
            ->withPivot(['custom_price', 'custom_duration_minutes', 'is_active', 'company_id'])
            ->withTimestamps()
            ->using(ProfessionalServiceLink::class);
    }

    /**
     * @return HasMany<ProfessionalWorkingHour, $this>
     */
    public function workingHours(): HasMany
    {
        return $this->hasMany(ProfessionalWorkingHour::class);
    }

    /**
     * @return HasMany<ProfessionalBreak, $this>
     */
    public function breaks(): HasMany
    {
        return $this->hasMany(ProfessionalBreak::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * @return HasMany<Payable, $this>
     */
    public function commissionPayables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    public function hasConfiguredWorkingHours(): bool
    {
        return $this->workingHours()->active()->exists();
    }
}
