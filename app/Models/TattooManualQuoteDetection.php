<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Orçamento que o tatuador digitou à mão no WhatsApp, aguardando o "1/2" dele. */
class TattooManualQuoteDetection extends Model
{
    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'deposit' => 'decimal:2', 'deposit_written' => 'boolean',
            'candidates' => 'array', 'resolved_at' => 'datetime'];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TattooAiConversation::class, 'tattoo_ai_conversation_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(TattooQuote::class, 'tattoo_quote_id');
    }
}
