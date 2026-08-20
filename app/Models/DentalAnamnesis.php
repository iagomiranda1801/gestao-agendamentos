<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['version', 'status', 'questionnaire_snapshot', 'answers', 'reviewed_by', 'completed_at', 'superseded_at'])]
class DentalAnamnesis extends Model
{
    protected $guarded = ['company_id', 'client_id', 'created_by'];

    protected function casts(): array
    {
        return ['questionnaire_snapshot' => 'array', 'answers' => 'array', 'completed_at' => 'datetime', 'superseded_at' => 'datetime'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'reviewed_by');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
