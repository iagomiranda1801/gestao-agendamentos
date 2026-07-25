<?php

namespace App\Models;

use App\Enums\CommissionType;
use Database\Factories\CompanyFinancialSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'default_commission_type',
    'default_commission_value',
    'materials_reserve_percentage',
    'business_reserve_percentage',
    'allow_partial_payments',
    'allow_unpaid_completion',
    'default_payment_due_days',
])]
class CompanyFinancialSetting extends Model
{
    /** @use HasFactory<CompanyFinancialSettingFactory> */
    use HasFactory;

    protected $guarded = ['company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_commission_type' => CommissionType::class,
            'default_commission_value' => 'decimal:4',
            'materials_reserve_percentage' => 'decimal:4',
            'business_reserve_percentage' => 'decimal:4',
            'allow_partial_payments' => 'boolean',
            'allow_unpaid_completion' => 'boolean',
            'default_payment_due_days' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function ownerAllocationPercentage(): string
    {
        $commissionEquivalent = $this->default_commission_type === CommissionType::Percentage
            ? (string) $this->default_commission_value
            : '0';

        return bcsub(
            bcsub(
                bcsub('100', $commissionEquivalent, 4),
                (string) $this->materials_reserve_percentage,
                4,
            ),
            (string) $this->business_reserve_percentage,
            4,
        );
    }
}
