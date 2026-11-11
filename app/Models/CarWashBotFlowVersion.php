<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CarWashBotFlowVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(CarWashBotFlow::class, 'car_wash_bot_flow_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['flow' => 'Uma versão publicada não pode ser alterada. Restaure como rascunho.']));
        static::deleting(fn () => throw ValidationException::withMessages(['flow' => 'O histórico publicado deve ser preservado.']));
    }
}
