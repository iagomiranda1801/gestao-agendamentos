<?php

namespace App\Models;

use Database\Factories\CompanyOrderSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'online_ordering_enabled',
    'pickup_enabled',
    'delivery_enabled',
    'delivery_fee_cents',
    'min_order_cents',
    'delivery_radius_note',
    'orders_whatsapp_notify',
    'whatsapp_order_link_bot_enabled',
    'page_title',
    'page_description',
    'confirmation_message',
    'primary_color',
])]
class CompanyOrderSetting extends Model
{
    /** @use HasFactory<CompanyOrderSettingFactory> */
    use HasFactory;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'online_ordering_enabled' => 'boolean',
            'pickup_enabled' => 'boolean',
            'delivery_enabled' => 'boolean',
            'delivery_fee_cents' => 'integer',
            'min_order_cents' => 'integer',
            'orders_whatsapp_notify' => 'boolean',
            'whatsapp_order_link_bot_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
