<?php

namespace App\Models;

use App\Enums\Weekday;
use Database\Factories\CompanyBusinessHourFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'weekday',
    'start_time',
    'end_time',
    'sort_order',
    'is_active',
])]
class CompanyBusinessHour extends Model
{
    /** @use HasFactory<CompanyBusinessHourFactory> */
    use HasFactory;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
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

    public function weekdayEnum(): Weekday
    {
        return Weekday::from($this->weekday);
    }

    /**
     * @param  Builder<CompanyBusinessHour>  $query
     * @return Builder<CompanyBusinessHour>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
