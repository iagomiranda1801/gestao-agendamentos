<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable([
    'name',
    'slug',
    'description',
    'price',
    'duration_minutes',
    'buffer_before_minutes',
    'buffer_after_minutes',
    'color',
    'sort_order',
    'is_bookable',
    'is_sellable',
    'is_online_booking_enabled',
    'notes',
    'is_active',
])]
class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [
        'company_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (Service $service): void {
            if (filled($service->slug)) {
                return;
            }

            $service->slug = static::generateUniqueSlug($service->name, $service->company_id);
        });
    }

    public static function generateUniqueSlug(string $name, ?int $companyId, ?int $ignoreId = null): string
    {
        $baseSlug = Str::slug($name);
        $slug = $baseSlug;
        $counter = 1;

        while (static::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
            ->where('slug', $slug)
            ->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_minutes' => 'integer',
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'sort_order' => 'integer',
            'is_bookable' => 'boolean',
            'is_sellable' => 'boolean',
            'is_online_booking_enabled' => 'boolean',
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
     * @return HasMany<ServiceProductConsumption, $this>
     */
    public function consumptions(): HasMany
    {
        return $this->hasMany(ServiceProductConsumption::class);
    }

    /**
     * @return BelongsToMany<Professional, $this>
     */
    public function professionals(): BelongsToMany
    {
        return $this->belongsToMany(Professional::class, 'professional_service')
            ->withPivot(['custom_price', 'custom_duration_minutes', 'is_active', 'company_id', 'commission_type', 'commission_value'])
            ->withTimestamps()
            ->using(ProfessionalServiceLink::class);
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
     * @return HasMany<SaleItem, $this>
     */
    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function getEstimatedMaterialCost(): string
    {
        $total = '0';

        $this->loadMissing('consumptions.product');

        foreach ($this->consumptions as $consumption) {
            $lineCost = bcmul(
                (string) $consumption->quantity,
                $consumption->product->getCurrentUnitCost(),
                6,
            );

            $total = bcadd($total, $lineCost, 6);
        }

        return $total;
    }

    public function getEstimatedGrossMargin(): string
    {
        return bcsub((string) $this->price, $this->getEstimatedMaterialCost(), 2);
    }

    public function getEstimatedGrossMarginPercentage(): ?string
    {
        if (bccomp((string) $this->price, '0', 2) <= 0) {
            return null;
        }

        $margin = $this->getEstimatedGrossMargin();

        return bcmul(
            bcdiv($margin, (string) $this->price, 6),
            '100',
            2,
        );
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeBookable(Builder $query): Builder
    {
        return $query->where('is_bookable', true);
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_sellable', true);
    }

    /**
     * @param  Builder<Service>  $query
     * @return Builder<Service>
     */
    public function scopeAvailableForOnlineBooking(Builder $query): Builder
    {
        return $query
            ->where('is_online_booking_enabled', true)
            ->where('is_bookable', true);
    }
}
