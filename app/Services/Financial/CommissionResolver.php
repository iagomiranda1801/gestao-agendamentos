<?php

namespace App\Services\Financial;

use App\Enums\CommissionType;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Support\DecimalMoney;

class CommissionResolver
{
    public function __construct(
        protected CompanyFinancialSettingService $financialSettingService,
        protected FinancialDistributionValidator $distributionValidator,
    ) {}

    public function resolve(
        Company $company,
        Professional $professional,
        Service $service,
        string $finalAmount,
    ): CommissionResult {
        $settings = $this->financialSettingService->getOrCreate($company);

        $link = $service->professionals()
            ->where('professionals.id', $professional->getKey())
            ->wherePivot('company_id', $company->getKey())
            ->first()?->pivot;

        if ($link !== null && $link->commission_type !== null) {
            $type = $link->commission_type instanceof CommissionType
                ? $link->commission_type
                : CommissionType::from((string) $link->commission_type);
            $value = (string) ($link->commission_value ?? '0');
            $source = 'custom';
        } else {
            $type = $settings->default_commission_type;
            $value = (string) $settings->default_commission_value;
            $source = 'default';
        }

        return $this->calculate(
            $type,
            $value,
            $finalAmount,
            $source,
            (string) $settings->materials_reserve_percentage,
            (string) $settings->business_reserve_percentage,
        );
    }

    public function calculate(
        CommissionType $type,
        string $configuredValue,
        string $finalAmount,
        string $source,
        string $materialsReservePercentage,
        string $businessReservePercentage,
    ): CommissionResult {
        return match ($type) {
            CommissionType::None => new CommissionResult(
                type: CommissionType::None,
                configuredValue: '0.0000',
                equivalentPercentage: '0.0000',
                calculatedAmount: '0.00',
                source: $source,
            ),
            CommissionType::Percentage => $this->resolvePercentage(
                $configuredValue,
                $finalAmount,
                $source,
                $materialsReservePercentage,
                $businessReservePercentage,
            ),
            CommissionType::Fixed => $this->resolveFixed(
                $configuredValue,
                $finalAmount,
                $source,
            ),
        };
    }

    protected function resolvePercentage(
        string $percentage,
        string $finalAmount,
        string $source,
        string $materialsReservePercentage,
        string $businessReservePercentage,
    ): CommissionResult {
        $this->distributionValidator->validateCustomCommissionPercentage(
            $materialsReservePercentage,
            $businessReservePercentage,
            $percentage,
        );

        $calculatedAmount = DecimalMoney::round(
            bcdiv(bcmul($finalAmount, $percentage, 6), '100', 6),
        );

        return new CommissionResult(
            type: CommissionType::Percentage,
            configuredValue: $percentage,
            equivalentPercentage: $percentage,
            calculatedAmount: $calculatedAmount,
            source: $source,
        );
    }

    protected function resolveFixed(
        string $fixedValue,
        string $finalAmount,
        string $source,
    ): CommissionResult {
        $this->distributionValidator->validateFixedCommissionValue($fixedValue);
        $this->distributionValidator->validateFixedCommissionAgainstFinalAmount($fixedValue, $finalAmount);

        $calculatedAmount = bccomp($finalAmount, '0', 2) > 0
            ? (bccomp($fixedValue, $finalAmount, 2) > 0 ? $finalAmount : $fixedValue)
            : '0.00';

        $calculatedAmount = DecimalMoney::round($calculatedAmount);

        $equivalentPercentage = bccomp($finalAmount, '0', 2) > 0
            ? bcdiv(bcmul($calculatedAmount, '100', 6), $finalAmount, 4)
            : '0.0000';

        return new CommissionResult(
            type: CommissionType::Fixed,
            configuredValue: $fixedValue,
            equivalentPercentage: $equivalentPercentage,
            calculatedAmount: $calculatedAmount,
            source: $source,
        );
    }
}
