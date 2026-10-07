<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TattooPaymentReceipt extends Model
{
    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['analysis' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TattooRequest::class, 'tattoo_request_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(TattooQuote::class, 'tattoo_quote_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TattooAiConversation::class, 'tattoo_ai_conversation_id');
    }
}
