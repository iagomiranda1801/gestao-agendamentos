<?php

namespace App\Services\Financial;

class PayableInstallmentCalculator
{
    public function calculateCashOutflow(
        string $settledPrincipalAmount,
        string $interestAmount = '0.00',
        string $penaltyAmount = '0.00',
        string $feeAmount = '0.00',
        string $discountAmount = '0.00',
    ): string {
        $total = bcadd($settledPrincipalAmount, $interestAmount, 2);
        $total = bcadd($total, $penaltyAmount, 2);
        $total = bcadd($total, $feeAmount, 2);

        return bcsub($total, $discountAmount, 2);
    }

    public function sumConfirmedSettledPrincipal(iterable $payments): string
    {
        $total = '0.00';

        foreach ($payments as $payment) {
            if (! $payment->isConfirmed()) {
                continue;
            }

            $total = bcadd($total, (string) $payment->settled_principal_amount, 2);
        }

        return $total;
    }

    public function calculateOutstandingAmount(string $originalAmount, string $settledPrincipalAmount): string
    {
        return bcsub($originalAmount, $settledPrincipalAmount, 2);
    }
}
