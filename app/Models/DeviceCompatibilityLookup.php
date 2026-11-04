<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DeviceCompatibilityLookup extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['results' => 'array', 'fetched_at' => 'datetime'];
    }
}
