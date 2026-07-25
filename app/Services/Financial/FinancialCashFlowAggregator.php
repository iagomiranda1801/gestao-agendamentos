<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\CashFlowSummary;
use App\Enums\FinancialTransactionDirection;
use App\Enums\FinancialTransactionType;
use App\Models\Company;
use App\Models\FinancialTransaction;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;

class FinancialCashFlowAggregator
{
    /**
     * @param  list<int>|null  $accountIds
     */
    public function aggregate(
        Company $company,
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal,
        ?array $accountIds = null,
    ): CashFlowSummary {
        $startUtc = CompanyDateTime::localToUtc($company, $startLocal);
        $endUtc = CompanyDateTime::localToUtc($company, $endLocal);

        $consolidated = $accountIds === null || $accountIds === [];

        $inflows = $this->sumMovements(
            $company,
            FinancialTransactionDirection::Inbound,
            $startUtc,
            $endUtc,
            $accountIds,
            $consolidated,
        );

        $outflows = $this->sumMovements(
            $company,
            FinancialTransactionDirection::Outbound,
            $startUtc,
            $endUtc,
            $accountIds,
            $consolidated,
        );

        $netFlow = bcsub($inflows, $outflows, 2);
        $initialBalance = $this->balanceAt($company, $startUtc, $accountIds);
        $finalBalance = bcadd($initialBalance, $netFlow, 2);

        return new CashFlowSummary(
            initialBalance: $this->formatAmount($initialBalance),
            inflows: $this->formatAmount($inflows),
            outflows: $this->formatAmount($outflows),
            netFlow: $this->formatAmount($netFlow),
            finalBalance: $this->formatAmount($finalBalance),
        );
    }

    /**
     * @param  list<int>|null  $accountIds
     */
    protected function sumMovements(
        Company $company,
        FinancialTransactionDirection $direction,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
        ?array $accountIds,
        bool $consolidated,
    ): string {
        $query = FinancialTransaction::query()
            ->where('company_id', $company->getKey())
            ->where('direction', $direction)
            ->whereNull('reversed_at')
            ->whereNot('type', FinancialTransactionType::Reversal)
            ->whereBetween('occurred_at', [$startUtc, $endUtc]);

        if ($accountIds !== null && $accountIds !== []) {
            $query->whereIn('financial_account_id', $accountIds);
        }

        if ($consolidated) {
            $excludedTypes = $direction === FinancialTransactionDirection::Inbound
                ? [FinancialTransactionType::TransferIn]
                : [FinancialTransactionType::TransferOut];

            $query->whereNotIn('type', $excludedTypes);
        }

        return $this->formatAmount($query->sum('amount'));
    }

    /**
     * @param  list<int>|null  $accountIds
     */
    protected function balanceAt(
        Company $company,
        CarbonImmutable $momentUtc,
        ?array $accountIds,
    ): string {
        $query = FinancialTransaction::query()
            ->where('company_id', $company->getKey())
            ->whereNull('reversed_at')
            ->where('occurred_at', '<', $momentUtc);

        if ($accountIds !== null && $accountIds !== []) {
            $query->whereIn('financial_account_id', $accountIds);
        }

        $balance = '0.0000';

        foreach ($query->get(['direction', 'amount']) as $transaction) {
            $amount = (string) $transaction->amount;

            $balance = $transaction->direction === FinancialTransactionDirection::Inbound
                ? bcadd($balance, $amount, 4)
                : bcsub($balance, $amount, 4);
        }

        return $this->formatAmount($balance);
    }

    protected function formatAmount(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }
}
