<?php

namespace App\Models;

use App\Enums\WhatsAppCampaignAudience;
use App\Enums\WhatsAppCampaignStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'audience_type',
    'selected_client_ids',
    'status',
    'message_template',
    'send_interval_seconds',
    'total_recipients',
    'sent_count',
    'accepted_count',
    'failed_count',
    'skipped_count',
    'started_at',
    'completed_at',
    'cancelled_at',
])]
class WhatsAppCampaign extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_campaigns';

    protected $guarded = [
        'company_id',
        'created_by',
        'cancelled_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'audience_type' => WhatsAppCampaignAudience::class,
            'selected_client_ids' => 'array',
            'status' => WhatsAppCampaignStatus::class,
            'send_interval_seconds' => 'integer',
            'total_recipients' => 'integer',
            'sent_count' => 'integer',
            'accepted_count' => 'integer',
            'failed_count' => 'integer',
            'skipped_count' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * @return HasMany<WhatsAppCampaignRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(WhatsAppCampaignRecipient::class, 'whatsapp_campaign_id');
    }
}
