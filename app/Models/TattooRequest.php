<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TattooRequest extends Model
{
    protected $guarded = ['id', 'company_id'];

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

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(WhatsAppBotConversation::class, 'whatsapp_bot_conversation_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(TattooRequestImage::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(TattooQuote::class);
    }
}
