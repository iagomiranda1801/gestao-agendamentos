<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TattooAiMessage extends Model
{
    protected $guarded = ['id'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(TattooAiConversation::class, 'tattoo_ai_conversation_id');
    }
}
