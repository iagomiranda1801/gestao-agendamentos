<?php

namespace App\Models;

use App\Enums\ProductType;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'measurement_unit_id',
    'name',
    'sku',
    'type',
    'description',
    'reference_unit_cost',
    'minimum_stock',
    'tracks_stock',
    'notes',
    'is_active',
])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [
        'company_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'reference_unit_cost' => 'decimal:6',
            'minimum_stock' => 'decimal:4',
            'tracks_stock' => 'boolean',
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
     * @return BelongsTo<MeasurementUnit, $this>
     */
    public function measurementUnit(): BelongsTo
    {
        return $this->belongsTo(MeasurementUnit::class);
    }

    /**
     * @return HasMany<ServiceProductConsumption, $this>
     */
    public function serviceConsumptions(): HasMany
    {
        return $this->hasMany(ServiceProductConsumption::class);
    }

    /**
     * @return HasOne<InventoryBalance, $this>
     */
    public function inventoryBalance(): HasOne
    {
        return $this->hasOne(InventoryBalance::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    public function getCurrentStockQuantity(): string
    {
        if ($this->relationLoaded('inventoryBalance')) {
            return (string) ($this->inventoryBalance?->quantity_on_hand ?? '0');
        }

        $balance = $this->inventoryBalance()->first();

        return (string) ($balance?->quantity_on_hand ?? '0');
    }

    public function getCurrentAverageUnitCost(): string
    {
        if ($this->relationLoaded('inventoryBalance')) {
            $average = $this->inventoryBalance?->average_unit_cost;

            if ($average !== null && bccomp((string) $average, '0', 6) > 0) {
                return (string) $average;
            }

            return (string) $this->reference_unit_cost;
        }

        $balance = $this->inventoryBalance()->first();

        if ($balance && bccomp((string) $balance->average_unit_cost, '0', 6) > 0) {
            return (string) $balance->average_unit_cost;
        }

        return (string) $this->reference_unit_cost;
    }

    public function getCurrentUnitCost(): string
    {
        return $this->getCurrentAverageUnitCost();
    }

    public function getCurrentStockValue(): string
    {
        return bcmul($this->getCurrentStockQuantity(), $this->getCurrentAverageUnitCost(), 6);
    }

    public function isBelowMinimumStock(): bool
    {
        if (! $this->tracks_stock) {
            return false;
        }

        $current = $this->getCurrentStockQuantity();
        $minimum = (string) $this->minimum_stock;

        if (bccomp($minimum, '0', 4) <= 0) {
            return bccomp($current, '0', 4) <= 0;
        }

        return bccomp($current, $minimum, 4) <= 0;
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeTracksStock(Builder $query): Builder
    {
        return $query->where('tracks_stock', true);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeConsumable(Builder $query): Builder
    {
        return $query->where('type', ProductType::Consumable);
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeAsset(Builder $query): Builder
    {
        return $query->where('type', ProductType::Asset);
    }
}
