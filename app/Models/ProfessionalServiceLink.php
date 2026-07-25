<?php

namespace App\Models;

use App\Enums\CommissionType;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ProfessionalServiceLink extends Pivot
{
    public $incrementing = true;

    protected $table = 'professional_service';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'custom_price' => 'decimal:2',
            'custom_duration_minutes' => 'integer',
            'is_active' => 'boolean',
            'commission_type' => CommissionType::class,
            'commission_value' => 'decimal:4',
        ];
    }
}
