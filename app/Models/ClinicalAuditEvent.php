<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['action', 'entity_type', 'entity_id', 'ip_address', 'metadata', 'occurred_at'])]
class ClinicalAuditEvent extends Model
{
    protected $guarded = ['company_id', 'client_id', 'user_id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'occurred_at' => 'datetime'];
    }
}
