<?php

namespace App\DataTransferObjects\Financial;

readonly class CashFlowSummary
{
    public function __construct(
        public string $initialBalance,
        public string $inflows,
        public string $outflows,
        public string $netFlow,
        public string $finalBalance,
    ) {}
}
