<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'instance_name',
    'instance_token',
    'sender_phone',
    'status',
    'qr_code',
    'is_default',
    'connected_at',
])]
class CompanyWhatsAppInstance extends Model
{
    use HasFactory;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'instance_token' => 'encrypted',
            'is_default' => 'boolean',
            'connected_at' => 'datetime',
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
     * @return HasMany<WhatsAppContact, $this>
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(WhatsAppContact::class, 'company_whatsapp_instance_id');
    }

    /**
     * @param  Builder<CompanyWhatsAppInstance>  $query
     * @return Builder<CompanyWhatsAppInstance>
     */
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
