<?php

namespace App\Services\Financial;

use App\Enums\CommissionType;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\DecimalMoney;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AttendanceFinancialCalculator
{
    public function __construct(
        protected CommissionResolver $commissionResolver,
        protected FinancialDistributionValidator $distributionValidator,
    ) {}

    public function calculateFinalAmount(string $grossAmount, string $discountAmount): string
    {
        $this->validateNonNegative($grossAmount, 'gross_amount');
        $this->validateNonNegative($discountAmount, 'discount_amount');

        if (bccomp($discountAmount, $grossAmount, 2) > 0) {
            throw ValidationException::withMessages([
                'discount_amount' => 'O desconto não pode ser maior que o valor bruto.',
            ]);
        }

        return DecimalMoney::round(bcsub($grossAmount, $discountAmount, 6));
    }

    public function calculateDistribution(
        CommissionType $commissionType,
        string $commissionValue,
        string $finalAmount,
        string $materialsReservePercentage,
        string $businessReservePercentage,
    ): AttendanceFinancialResult {
        $this->validateNonNegative($finalAmount, 'final_amount');

        $commissionResult = $this->commissionResolver->calculate(
            $commissionType,
            $commissionValue,
            $finalAmount,
            'snapshot',
            $materialsReservePercentage,
            $businessReservePercentage,
        );

        $materialsReserveAmount = $this->percentageOf($finalAmount, $materialsReservePercentage);
        $businessReserveAmount = $this->percentageOf($finalAmount, $businessReservePercentage);

        $ownerAllocationAmount = DecimalMoney::round(bcsub(
            bcsub(
                bcsub($finalAmount, $commissionResult->calculatedAmount, 6),
                $materialsReserveAmount,
                6,
            ),
            $businessReserveAmount,
            6,
        ));

        if (bccomp($ownerAllocationAmount, '0', 2) < 0) {
            throw ValidationException::withMessages([
                'distribution' => 'A distribuição financeira não pode resultar em valor negativo para o proprietário.',
            ]);
        }

        $ownerAllocationPercentage = bccomp($finalAmount, '0', 2) > 0
            ? bcdiv(bcmul($ownerAllocationAmount, '100', 6), $finalAmount, 4)
            : '0.0000';

        return new AttendanceFinancialResult(
            finalAmount: $finalAmount,
            commissionType: $commissionResult->type,
            commissionValueSnapshot: $commissionResult->configuredValue,
            commissionAmount: $commissionResult->calculatedAmount,
            materialsReservePercentageSnapshot: $materialsReservePercentage,
            materialsReserveAmount: $materialsReserveAmount,
            businessReservePercentageSnapshot: $businessReservePercentage,
            businessReserveAmount: $businessReserveAmount,
            ownerAllocationPercentageSnapshot: $ownerAllocationPercentage,
            ownerAllocationAmount: $ownerAllocationAmount,
            paymentFeeAmount: '0.00',
            operationalResult: $this->calculateOperationalResult(
                $finalAmount,
                '0.00',
                $commissionResult->calculatedAmount,
                '0.00',
            ),
        );
    }

    /**
     * @param  Collection<int, Payment>|iterable<int, Payment>  $payments
     */
    public function sumConfirmedPaymentFees(iterable $payments): string
    {
        $total = '0.00';

        foreach ($payments as $payment) {
            if ($payment->status !== PaymentStatus::Confirmed) {
                continue;
            }

            $total = bcadd($total, (string) $payment->fee_amount, 2);
        }

        return DecimalMoney::round($total);
    }

    /**
     * @param  Collection<int, Payment>|iterable<int, Payment>  $payments
     */
    public function sumConfirmedPaymentNetAmounts(iterable $payments): string
    {
        $total = '0.00';

        foreach ($payments as $payment) {
            if ($payment->status !== PaymentStatus::Confirmed) {
                continue;
            }

            $total = bcadd($total, (string) $payment->net_amount, 2);
        }

        return DecimalMoney::round($total);
    }

    public function calculateNetAmount(string $amount, string $feeAmount): string
    {
        $this->validateNonNegative($amount, 'amount');
        $this->validateNonNegative($feeAmount, 'fee_amount');

        if (bccomp($feeAmount, $amount, 2) > 0) {
            throw ValidationException::withMessages([
                'fee_amount' => 'A taxa não pode ser maior que o valor do pagamento.',
            ]);
        }

        return DecimalMoney::round(bcsub($amount, $feeAmount, 6));
    }

    public function calculateOutstandingAmount(string $originalAmount, string $paidAmount): string
    {
        $this->validateNonNegative($originalAmount, 'original_amount');
        $this->validateNonNegative($paidAmount, 'paid_amount');

        if (bccomp($paidAmount, $originalAmount, 2) > 0) {
            throw ValidationException::withMessages([
                'paid_amount' => 'O valor pago não pode ser maior que o valor original.',
            ]);
        }

        return DecimalMoney::round(bcsub($originalAmount, $paidAmount, 6));
    }

    public function calculateOperationalResult(
        string $finalAmount,
        string $actualMaterialCost,
        string $commissionAmount,
        string $paymentFeeAmount,
    ): string {
        $this->validateNonNegative($finalAmount, 'final_amount');
        $this->validateNonNegative($actualMaterialCost, 'actual_material_cost');
        $this->validateNonNegative($commissionAmount, 'commission_amount');
        $this->validateNonNegative($paymentFeeAmount, 'payment_fee_amount');

        return DecimalMoney::round(bcsub(
            bcsub(
                bcsub($finalAmount, $actualMaterialCost, 6),
                $commissionAmount,
                6,
            ),
            $paymentFeeAmount,
            6,
        ));
    }

    protected function percentageOf(string $baseAmount, string $percentage): string
    {
        return DecimalMoney::round(
            bcdiv(bcmul($baseAmount, $percentage, 6), '100', 6),
        );
    }

    protected function validateNonNegative(string $amount, string $field): void
    {
        if (bccomp($amount, '0', 2) < 0) {
            throw ValidationException::withMessages([
                $field => 'O valor não pode ser negativo.',
            ]);
        }
    }
}
