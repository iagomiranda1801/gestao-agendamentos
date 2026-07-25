<?php

namespace App\DataTransferObjects\Financial;

use App\Enums\PaymentMethod;
use Carbon\CarbonInterface;

readonly class PayablePaymentData
{
    public function __construct(
        public string $settledPrincipalAmount,
        public PaymentMethod $method,
        public CarbonInterface $paidAt,
        public string $interestAmount = '0.00',
        public string $penaltyAmount = '0.00',
        public string $feeAmount = '0.00',
        public string $discountAmount = '0.00',
        public ?string $reference = null,
        public ?string $notes = null,
    ) {}
}
