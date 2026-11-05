<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class AssistanceBotFlowVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['flow' => 'Uma versão publicada não pode ser alterada. Restaure como rascunho.']));
        static::deleting(fn () => throw ValidationException::withMessages(['flow' => 'O histórico publicado deve ser preservado.']));
    }
}
