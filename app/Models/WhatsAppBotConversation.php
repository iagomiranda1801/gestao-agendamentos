<?php

namespace App\Models;

use App\Enums\WhatsAppBotConversationState;
use Database\Factories\WhatsAppBotConversationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id',
    'company_whatsapp_instance_id',
    'appointment_id',
    'phone_normalized',
    'remote_jid',
    'state',
    'data',
    'last_incoming_message_id',
    'last_activity_at',
    'expires_at',
    'finished_at',
    'finished_reason',
])]
class WhatsAppBotConversation extends Model
{
    /** @use HasFactory<WhatsAppBotConversationFactory> */
    use HasFactory;

    protected $table = 'whatsapp_bot_conversations';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => WhatsAppBotConversationState::class,
            'data' => 'array',
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
            'finished_at' => 'datetime',
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
     * @return BelongsTo<CompanyWhatsAppInstance, $this>
     */
    public function instance(): BelongsTo
    {
        return $this->belongsTo(CompanyWhatsAppInstance::class, 'company_whatsapp_instance_id');
    }

    /**
     * @return BelongsTo<Appointment, $this>
     */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function isActive(): bool
    {
        return $this->finished_at === null;
    }

    /**
     * @param  Builder<WhatsAppBotConversation>  $query
     * @return Builder<WhatsAppBotConversation>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('finished_at');
    }

    /**
     * @param  Builder<WhatsAppBotConversation>  $query
     * @return Builder<WhatsAppBotConversation>
     */
    public function scopeFinished(Builder $query): Builder
    {
        return $query->whereNotNull('finished_at');
    }
}
