<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CarWashBotFlow extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['revision' => 1, 'is_enabled' => false];

    protected function casts(): array
    {
        return ['draft' => 'array', 'revision' => 'integer', 'is_enabled' => 'boolean'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(CarWashBotFlowVersion::class);
    }
}
