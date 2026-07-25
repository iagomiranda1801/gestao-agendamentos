<?php

namespace App\Models;

use App\Enums\MeasurementUnitCategory;
use Database\Factories\MeasurementUnitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'symbol',
    'code',
    'category',
    'decimal_places',
    'is_active',
])]
class MeasurementUnit extends Model
{
    /** @use HasFactory<MeasurementUnitFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => MeasurementUnitCategory::class,
            'decimal_places' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * @param  Builder<MeasurementUnit>  $query
     * @return Builder<MeasurementUnit>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
