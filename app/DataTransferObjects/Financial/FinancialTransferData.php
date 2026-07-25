<?php

namespace App\DataTransferObjects\Financial;

use Carbon\CarbonInterface;

readonly class FinancialTransferData
{
    public function __construct(
        public int $fromFinancialAccountId,
        public int $toFinancialAccountId,
        public string $amount,
        public CarbonInterface $occurredAt,
        public string $description,
        public string $feeAmount = '0.00',
        public ?string $referenceKey = null,
    ) {}
}
