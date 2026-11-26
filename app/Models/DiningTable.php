<?php

namespace App\Models;

use Database\Factories\DiningTableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'number',
    'name',
    'sort_order',
    'is_active',
])]
class DiningTable extends Model
{
    /** @use HasFactory<DiningTableFactory> */
    use HasFactory;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
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
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    /**
     * @param  Builder<DiningTable>  $query
     * @return Builder<DiningTable>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function displayLabel(): string
    {
        $label = 'Mesa '.$this->number;
        $name = trim((string) $this->name);

        if ($name === '') {
            return $label;
        }

        return $label.' — '.$name;
    }

    public function currentOrder(): ?Order
    {
        if ($this->relationLoaded('orders')) {
            return $this->orders->first(fn (Order $order): bool => $order->isOpen());
        }

        return $this->orders()->open()->latest('id')->first();
    }
}
