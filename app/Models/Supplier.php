<?php

namespace App\Models;

use App\Support\PhoneNormalizer;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'trade_name',
    'document',
    'phone',
    'phone_normalized',
    'email',
    'contact_name',
    'notes',
    'is_active',
])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $guarded = [
        'company_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (Supplier $supplier): void {
            if ($supplier->phone === null) {
                $supplier->phone_normalized = null;

                return;
            }

            $supplier->phone_normalized = PhoneNormalizer::normalize($supplier->phone);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * @return HasMany<StockDocument, $this>
     */
    public function stockDocuments(): HasMany
    {
        return $this->hasMany(StockDocument::class);
    }

    /**
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }
}
