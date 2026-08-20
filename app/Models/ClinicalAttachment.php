<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'attachable_type', 'attachable_id', 'type', 'title', 'description', 'document_date', 'disk', 'path',
    'original_name', 'mime_type', 'size_bytes', 'deleted_by', 'deletion_reason',
])]
class ClinicalAttachment extends Model
{
    use SoftDeletes;

    protected $guarded = ['company_id', 'client_id', 'uploaded_by'];

    protected function casts(): array
    {
        return ['document_date' => 'date'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
