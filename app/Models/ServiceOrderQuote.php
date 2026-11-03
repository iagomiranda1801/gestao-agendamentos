<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class ServiceOrderQuote extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $quote) {
            if ($quote->getOriginal('published_at') !== null && $quote->isDirty(['items', 'total', 'deadline', 'valid_until', 'conditions', 'version', 'service_order_id', 'token', 'published_at'])) {
                throw ValidationException::withMessages(['quote' => 'Um orçamento disponibilizado não pode ser alterado. Crie uma nova versão.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['items' => 'array', 'total' => 'decimal:2', 'valid_until' => 'date', 'published_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    public function serviceOrder(): BelongsTo
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    public function expired(): bool
    {
        return $this->valid_until->format('Y-m-d') < now($this->serviceOrder->company->timezone ?: 'America/Sao_Paulo')->toDateString();
    }
}
