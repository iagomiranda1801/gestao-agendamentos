<?php

namespace App\Models;

use App\Enums\WhatsAppCampaignRecipientStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'phone',
    'email_snapshot',
    'name_snapshot',
    'message_snapshot',
    'status',
    'attempts',
    'error_message',
    'provider_message_id',
    'provider_status',
    'provider_response',
    'queued_at',
    'attempted_at',
    'sent_at',
])]
class WhatsAppCampaignRecipient extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_campaign_recipients';

    protected $guarded = [
        'company_id',
        'whatsapp_campaign_id',
        'client_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => WhatsAppCampaignRecipientStatus::class,
            'attempts' => 'integer',
            'provider_response' => 'array',
            'queued_at' => 'datetime',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
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
     * @return BelongsTo<WhatsAppCampaign, $this>
     */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(WhatsAppCampaign::class, 'whatsapp_campaign_id');
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
