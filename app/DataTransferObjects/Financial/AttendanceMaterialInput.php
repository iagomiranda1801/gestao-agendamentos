<?php

namespace App\DataTransferObjects\Financial;

readonly class AttendanceMaterialInput
{
    public function __construct(
        public int $productId,
        public string $plannedQuantity,
        public string $quantity,
        public ?string $notes = null,
    ) {}
}
