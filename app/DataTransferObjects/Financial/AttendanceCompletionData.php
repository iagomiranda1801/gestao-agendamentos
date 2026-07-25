<?php

namespace App\DataTransferObjects\Financial;

use Carbon\CarbonInterface;

readonly class AttendanceCompletionData
{
    /**
     * @param  list<AttendanceMaterialInput>  $materials
     * @param  list<PaymentData>  $payments
     */
    public function __construct(
        public string $discountAmount,
        public array $materials,
        public array $payments,
        public ?string $notes = null,
        public ?string $internalNotes = null,
        public ?CarbonInterface $completedAt = null,
        public ?string $grossAmount = null,
    ) {}
}
