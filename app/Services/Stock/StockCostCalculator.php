<?php

namespace App\Services\Stock;

class StockCostCalculator
{
    public function calculateInboundAverage(
        string $currentQuantity,
        string $currentAverage,
        string $inboundQuantity,
        string $inboundUnitCost,
    ): string {
        if (bccomp($currentQuantity, '0', 4) <= 0) {
            return $inboundUnitCost;
        }

        $totalValue = bcadd(
            bcmul($currentQuantity, $currentAverage, 6),
            bcmul($inboundQuantity, $inboundUnitCost, 6),
            6,
        );

        $totalQuantity = bcadd($currentQuantity, $inboundQuantity, 4);

        return bcdiv($totalValue, $totalQuantity, 6);
    }

    public function calculateLineTotal(string $quantity, string $unitCost): string
    {
        return bcmul($quantity, $unitCost, 6);
    }

    public function calculateOutboundTotal(string $quantity, string $unitCost): string
    {
        return $this->calculateLineTotal($quantity, $unitCost);
    }

    /**
     * @return array{quantity: string, average: string}
     */
    public function reverseInbound(
        string $currentQuantity,
        string $currentAverage,
        string $reverseQuantity,
        string $originalUnitCost,
    ): array {
        $newQuantity = bcsub($currentQuantity, $reverseQuantity, 4);

        if (bccomp($newQuantity, '0', 4) <= 0) {
            return [
                'quantity' => '0',
                'average' => $currentAverage,
            ];
        }

        $totalValue = bcsub(
            bcmul($currentQuantity, $currentAverage, 6),
            bcmul($reverseQuantity, $originalUnitCost, 6),
            6,
        );

        $newAverage = bcdiv($totalValue, $newQuantity, 6);

        if (bccomp($newAverage, '0', 6) < 0) {
            $newAverage = '0';
        }

        return [
            'quantity' => $newQuantity,
            'average' => $newAverage,
        ];
    }

    /**
     * @return array{quantity: string, average: string}
     */
    public function reverseOutbound(
        string $currentQuantity,
        string $currentAverage,
        string $reverseQuantity,
        string $originalUnitCost,
    ): array {
        $newQuantity = bcadd($currentQuantity, $reverseQuantity, 4);

        if (bccomp($currentQuantity, '0', 4) <= 0) {
            return [
                'quantity' => $newQuantity,
                'average' => $originalUnitCost,
            ];
        }

        $newAverage = $this->calculateInboundAverage(
            $currentQuantity,
            $currentAverage,
            $reverseQuantity,
            $originalUnitCost,
        );

        return [
            'quantity' => $newQuantity,
            'average' => $newAverage,
        ];
    }
}
