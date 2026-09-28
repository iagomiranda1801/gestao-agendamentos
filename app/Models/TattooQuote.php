<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TattooQuote extends Model
{
    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['amount_min' => 'decimal:2', 'amount_max' => 'decimal:2', 'deposit_amount' => 'decimal:2', 'valid_until' => 'date', 'sent_at' => 'datetime', 'accepted_at' => 'datetime'];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(TattooRequest::class, 'tattoo_request_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
