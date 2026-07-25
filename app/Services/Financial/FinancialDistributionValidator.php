<?php

namespace App\Services\Financial;

use Illuminate\Validation\ValidationException;

class FinancialDistributionValidator
{
    public function validatePercentages(
        string $materialsReservePercentage,
        string $businessReservePercentage,
        string $commissionPercentage,
    ): void {
        $total = bcadd(
            bcadd($materialsReservePercentage, $businessReservePercentage, 4),
            $commissionPercentage,
            4,
        );

        if (bccomp($total, '100', 4) > 0) {
            throw ValidationException::withMessages([
                'distribution' => 'A soma da comissão com as reservas não pode ultrapassar 100%.',
            ]);
        }
    }

    public function validateCustomCommissionPercentage(
        string $materialsReservePercentage,
        string $businessReservePercentage,
        string $commissionPercentage,
    ): void {
        if (bccomp($commissionPercentage, '0', 4) < 0 || bccomp($commissionPercentage, '100', 4) > 0) {
            throw ValidationException::withMessages([
                'commission_value' => 'O percentual de comissão deve estar entre 0 e 100.',
            ]);
        }

        $this->validatePercentages(
            $materialsReservePercentage,
            $businessReservePercentage,
            $commissionPercentage,
        );
    }

    public function validateFixedCommissionValue(string $commissionValue): void
    {
        if (bccomp($commissionValue, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'commission_value' => 'O valor fixo da comissão não pode ser negativo.',
            ]);
        }
    }

    public function validateFixedCommissionAgainstFinalAmount(
        string $commissionValue,
        string $finalAmount,
    ): void {
        if (bccomp($finalAmount, '0', 2) <= 0) {
            return;
        }

        if (bccomp($commissionValue, $finalAmount, 2) > 0) {
            throw ValidationException::withMessages([
                'commission_value' => 'A comissão fixa não pode ser maior que o valor final do atendimento.',
            ]);
        }
    }

    public function calculateOwnerAllocationPercentage(
        string $materialsReservePercentage,
        string $businessReservePercentage,
        string $commissionEquivalentPercentage,
    ): string {
        return bcsub(
            bcsub(
                bcsub('100', $commissionEquivalentPercentage, 4),
                $materialsReservePercentage,
                4,
            ),
            $businessReservePercentage,
            4,
        );
    }
}
