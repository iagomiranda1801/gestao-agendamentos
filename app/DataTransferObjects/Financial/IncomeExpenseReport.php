<?php

namespace App\DataTransferObjects\Financial;

use Illuminate\Support\Collection;

readonly class IncomeExpenseReport
{
    /**
     * @param  Collection<int, IncomeExpenseReportRow>  $rows
     */
    public function __construct(
        public string $incomeTotal,
        public string $expenseTotal,
        public string $balance,
        public Collection $rows,
        public string $periodStartLabel,
        public string $periodEndLabel,
        public string $periodStartFile,
        public string $periodEndFile,
    ) {}
}
