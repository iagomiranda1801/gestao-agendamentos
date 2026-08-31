<?php

namespace App\Models;

use App\Enums\AdminAuditAction;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'actor_id',
    'actor_name',
    'actor_email',
    'company_id',
    'action',
    'subject_type',
    'subject_id',
    'subject_label',
    'before',
    'after',
    'metadata',
    'occurred_at',
])]
class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'action' => AdminAuditAction::class,
            'before' => 'array',
            'after' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
