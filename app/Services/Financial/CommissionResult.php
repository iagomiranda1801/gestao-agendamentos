<?php

namespace App\Services\Financial;

use App\Enums\CommissionType;

readonly class CommissionResult
{
    public function __construct(
        public CommissionType $type,
        public string $configuredValue,
        public string $equivalentPercentage,
        public string $calculatedAmount,
        public string $source,
    ) {}
}
