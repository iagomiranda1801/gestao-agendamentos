<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TattooRequestImage extends Model
{
    protected $guarded = ['id', 'company_id'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TattooRequest::class, 'tattoo_request_id');
    }
}
