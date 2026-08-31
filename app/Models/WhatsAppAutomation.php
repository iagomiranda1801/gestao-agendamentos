<?php

namespace App\Models;

use App\Enums\WhatsAppAutomationType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'type',
    'is_enabled',
    'delay_value',
    'cooldown_days',
    'quiet_hours_start',
    'quiet_hours_end',
    'message_template',
])]
class WhatsAppAutomation extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_automations';

    protected $guarded = [
        'company_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => WhatsAppAutomationType::class,
            'is_enabled' => 'boolean',
            'delay_value' => 'integer',
            'cooldown_days' => 'integer',
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
     * @return HasMany<WhatsAppAutomationSend, $this>
     */
    public function sends(): HasMany
    {
        return $this->hasMany(WhatsAppAutomationSend::class);
    }
}
