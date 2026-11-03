<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceOrder extends Model
{
    protected $guarded = ['id', 'company_id'];

    public const STATUSES = [
        'analysis' => 'Em análise', 'awaiting_approval' => 'Aguardando aprovação',
        'approved' => 'Aprovado', 'awaiting_part' => 'Aguardando peça',
        'repairing' => 'Em conserto', 'ready' => 'Pronto para retirada',
        'delivered' => 'Entregue', 'returned' => 'Devolvido sem conserto', 'reversed' => 'Conserto estornado',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(ServiceOrderQuote::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ServiceOrderEvent::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ServiceOrderMessage::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ServiceOrderPhoto::class);
    }

    public function latestQuote(): ?ServiceOrderQuote
    {
        return $this->quotes()->orderByDesc('version')->first();
    }
}
