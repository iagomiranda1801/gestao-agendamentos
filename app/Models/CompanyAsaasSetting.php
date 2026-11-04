<?php

namespace App\Models;

use App\Enums\AsaasEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'environment',
    'api_key',
    'webhook_token',
    'financial_account_id',
    'is_enabled',
])]
class CompanyAsaasSetting extends Model
{
    protected $hidden = [
        'api_key',
        'webhook_token',
    ];

    protected $guarded = [
        'company_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'environment' => AsaasEnvironment::class,
            'api_key' => 'encrypted',
            'webhook_token' => 'encrypted',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<FinancialAccount, $this>
     */
    public function financialAccount(): BelongsTo
    {
        return $this->belongsTo(FinancialAccount::class);
    }

    public function isReady(): bool
    {
        return $this->is_enabled && filled($this->api_key);
    }
}
