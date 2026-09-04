<?php

namespace App\Models;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use Database\Factories\ModulePriceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'module',
    'interval',
    'price_cents',
])]
class ModulePrice extends Model
{
    /** @use HasFactory<ModulePriceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'module' => CompanyModule::class,
            'interval' => BillingInterval::class,
            'price_cents' => 'integer',
        ];
    }
}
