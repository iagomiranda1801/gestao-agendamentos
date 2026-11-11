<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarWashBotConversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['state' => 'array', 'human_takeover' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(CompanyWhatsAppInstance::class, 'company_whatsapp_instance_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CarWashBotFlowVersion::class, 'car_wash_bot_flow_version_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CarWashBotMessage::class);
    }
}
