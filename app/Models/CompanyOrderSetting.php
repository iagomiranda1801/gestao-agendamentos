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
    'dine_in_enabled',
    'delivery_fee_cents',
    'min_order_cents',
    'delivery_radius_note',
    'orders_whatsapp_notify',
    'whatsapp_order_link_bot_enabled',
    'page_title',
    'page_description',
    'confirmation_message',
    'primary_color',
    'receipt_paper_mm',
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
            'dine_in_enabled' => 'boolean',
            'delivery_fee_cents' => 'integer',
            'min_order_cents' => 'integer',
            'orders_whatsapp_notify' => 'boolean',
            'whatsapp_order_link_bot_enabled' => 'boolean',
            'receipt_paper_mm' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function receiptPaperMm(): int
    {
        return in_array((int) $this->receipt_paper_mm, [58, 80], true)
            ? (int) $this->receipt_paper_mm
            : 80;
    }
}
