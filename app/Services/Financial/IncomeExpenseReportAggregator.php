<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\IncomeExpenseReport;
use App\DataTransferObjects\Financial\IncomeExpenseReportRow;
use App\Enums\FinancialTransactionDirection;
use App\Enums\FinancialTransactionType;
use App\Models\Company;
use App\Models\FinancialTransaction;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class IncomeExpenseReportAggregator
{
    public function aggregate(
        Company $company,
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal,
    ): IncomeExpenseReport {
        $startUtc = CompanyDateTime::localToUtc($company, $startLocal);
        $endUtc = CompanyDateTime::localToUtc($company, $endLocal);

        $transactions = $this->movementQuery($company, $startUtc, $endUtc)
            ->with('financialAccount')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $incomeTotal = '0.00';
        $expenseTotal = '0.00';

        $rows = $transactions->map(function (FinancialTransaction $transaction) use ($company, &$incomeTotal, &$expenseTotal): IncomeExpenseReportRow {
            $amount = $this->formatAmount($transaction->amount);
            $isInbound = $transaction->direction === FinancialTransactionDirection::Inbound;

            if ($isInbound) {
                $incomeTotal = bcadd($incomeTotal, $amount, 2);
            } else {
                $expenseTotal = bcadd($expenseTotal, $amount, 2);
            }

            $type = $transaction->type;

            return new IncomeExpenseReportRow(
                occurredAtLocal: CompanyDateTime::formatLocal($company, $transaction->occurred_at),
                typeLabel: $type instanceof FinancialTransactionType ? $type->label() : (string) $type,
                description: (string) $transaction->description,
                accountName: (string) ($transaction->financialAccount?->name ?? '—'),
                directionLabel: $isInbound
                    ? FinancialTransactionDirection::Inbound->label()
                    : FinancialTransactionDirection::Outbound->label(),
                amount: $amount,
                isInbound: $isInbound,
            );
        });

        return new IncomeExpenseReport(
            incomeTotal: $this->formatAmount($incomeTotal),
            expenseTotal: $this->formatAmount($expenseTotal),
            balance: $this->formatAmount(bcsub($incomeTotal, $expenseTotal, 2)),
            rows: $rows->values(),
            periodStartLabel: $startLocal->format('d/m/Y'),
            periodEndLabel: $endLocal->format('d/m/Y'),
            periodStartFile: $startLocal->format('Y-m-d'),
            periodEndFile: $endLocal->format('Y-m-d'),
        );
    }

    /**
     * @return Collection<int, IncomeExpenseReportRow>
     */
    public function rows(Company $company, CarbonImmutable $startLocal, CarbonImmutable $endLocal): Collection
    {
        return $this->aggregate($company, $startLocal, $endLocal)->rows;
    }

    /**
     * @return Builder<FinancialTransaction>
     */
    protected function movementQuery(Company $company, CarbonImmutable $startUtc, CarbonImmutable $endUtc): Builder
    {
        return FinancialTransaction::query()
            ->where('company_id', $company->getKey())
            ->whereNull('reversed_at')
            ->whereNot('type', FinancialTransactionType::Reversal)
            ->whereNotIn('type', [
                FinancialTransactionType::TransferIn,
                FinancialTransactionType::TransferOut,
            ])
            ->whereBetween('occurred_at', [$startUtc, $endUtc]);
    }

    protected function formatAmount(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }
}
