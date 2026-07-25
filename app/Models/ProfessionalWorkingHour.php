<?php

namespace App\Models;

use App\Enums\Weekday;
use Database\Factories\ProfessionalWorkingHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'weekday',
    'start_time',
    'end_time',
    'valid_from',
    'valid_until',
    'sort_order',
    'is_active',
])]
class ProfessionalWorkingHour extends Model
{
    /** @use HasFactory<ProfessionalWorkingHourFactory> */
    use HasFactory;

    protected $guarded = [
        'company_id',
        'professional_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
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
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function weekdayEnum(): Weekday
    {
        return Weekday::from($this->weekday);
    }

    /**
     * @param  Builder<ProfessionalWorkingHour>  $query
     * @return Builder<ProfessionalWorkingHour>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
