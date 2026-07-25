<?php

namespace App\DataTransferObjects\Financial;

readonly class ExpenseByCategoryRow
{
    public function __construct(
        public int $categoryId,
        public string $categoryName,
        public string $competenceAmount,
        public string $paidAmount,
        public string $outstandingAmount,
        public string $percentage,
    ) {}
}
