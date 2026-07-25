<?php

namespace App\DataTransferObjects\Financial;

readonly class FinancialDashboardSummary
{
    public function __construct(
        public string $completedRevenue,
        public string $received,
        public string $outstanding,
        public string $materialCost,
        public string $commissions,
        public string $materialReserve,
        public string $businessReserve,
        public string $ownerAllocation,
        public string $paymentFees,
        public string $operationalResult,
    ) {}
}
