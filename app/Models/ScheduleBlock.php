<?php

namespace App\Models;

use App\Enums\ScheduleBlockType;
use Carbon\CarbonInterface;
use Database\Factories\ScheduleBlockFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'professional_id',
    'type',
    'title',
    'start_at',
    'end_at',
    'is_all_day',
    'reason',
    'is_active',
])]
class ScheduleBlock extends Model
{
    /** @use HasFactory<ScheduleBlockFactory> */
    use HasFactory;

    protected $guarded = [
        'company_id',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ScheduleBlockType::class,
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'is_all_day' => 'boolean',
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<ScheduleBlock>  $query
     * @return Builder<ScheduleBlock>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<ScheduleBlock>  $query
     * @return Builder<ScheduleBlock>
     */
    public function scopeInPeriod(Builder $query, CarbonInterface $start, CarbonInterface $end): Builder
    {
        return $query
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start);
    }
}
