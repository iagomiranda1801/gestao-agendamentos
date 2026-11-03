<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceOrderMessage extends Model
{
    protected $guarded = ['id'];

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(ServiceOrderQuote::class, 'quote_id');
    }
}
