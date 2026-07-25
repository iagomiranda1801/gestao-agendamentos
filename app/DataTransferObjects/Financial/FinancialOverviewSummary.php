<?php

namespace App\DataTransferObjects\Financial;

readonly class FinancialOverviewSummary
{
    public function __construct(
        public string $totalBalance,
        public string $cashBalance,
        public string $received,
        public string $paid,
        public string $netFlow,
        public string $receivablesOutstanding,
        public string $payablesOutstanding,
        public string $payablesOverdue,
        public string $managerialResult,
        public string $monthlyExpenses,
        public string $upcomingPayables,
    ) {}
}
