<?php

namespace App\Models;

use App\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class CompanyUser extends Pivot
{
    protected $table = 'company_user';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'is_active' => 'boolean',
        ];
    }
}
