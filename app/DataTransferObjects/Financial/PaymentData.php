<?php

namespace App\DataTransferObjects\Financial;

use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;

readonly class PaymentData
{
    public function __construct(
        public string $amount,
        public string $feeAmount,
        public PaymentMethod $method,
        public CarbonInterface $paidAt,
        public int $financialAccountId,
        public ?string $notes = null,
    ) {}
}
