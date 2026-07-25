<?php

namespace App\Services\Financial;

use App\Enums\CommissionType;

readonly class AttendanceFinancialResult
{
    public function __construct(
        public string $finalAmount,
        public CommissionType $commissionType,
        public string $commissionValueSnapshot,
        public string $commissionAmount,
        public string $materialsReservePercentageSnapshot,
        public string $materialsReserveAmount,
        public string $businessReservePercentageSnapshot,
        public string $businessReserveAmount,
        public string $ownerAllocationPercentageSnapshot,
        public string $ownerAllocationAmount,
        public string $paymentFeeAmount,
        public string $operationalResult,
    ) {}
}
