<?php

namespace App\DataTransferObjects\Financial;

readonly class ManagerialDreSummary
{
    public function __construct(
        public string $grossRevenue,
        public string $discounts,
        public string $netRevenue,
        public string $materialCost,
        public string $commissions,
        public string $paymentFees,
        public string $contributionMargin,
        public string $operationalExpenses,
        public string $operationalResult,
        public string $materialReserve,
        public string $businessReserve,
        public string $ownerAllocation,
    ) {}
}
