<?php

namespace App\DataTransferObjects\Financial;

readonly class IncomeExpenseReportRow
{
    public function __construct(
        public string $occurredAtLocal,
        public string $typeLabel,
        public string $description,
        public string $accountName,
        public string $directionLabel,
        public string $amount,
        public bool $isInbound,
    ) {}
}
