<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TattooAiConversation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['human_takeover' => 'boolean', 'collected_data' => 'array', 'last_interaction_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TattooRequest::class, 'tattoo_request_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(CompanyWhatsAppInstance::class, 'company_whatsapp_instance_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TattooAiMessage::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(TattooPaymentReceipt::class);
    }
}
