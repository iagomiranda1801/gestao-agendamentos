<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\ExpenseByCategoryRow;
use App\DataTransferObjects\Financial\FinancialOverviewSummary;
use App\Enums\FinancialAccountType;
use App\Enums\PayableStatus;
use App\Enums\ReceivableStatus;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\Receivable;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class FinancialOverviewAggregator
{
    public function __construct(
        protected FinancialCashFlowAggregator $cashFlowAggregator,
        protected ManagerialDreAggregator $managerialDreAggregator,
    ) {}

    public function aggregate(
        Company $company,
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal,
    ): FinancialOverviewSummary {
        $cashFlow = $this->cashFlowAggregator->aggregate($company, $startLocal, $endLocal);
        $dre = $this->managerialDreAggregator->aggregate($company, $startLocal, $endLocal);

        $totalBalance = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->with('balance')
            ->get()
            ->reduce(
                fn (string $carry, FinancialAccount $account): string => bcadd($carry, $account->getCurrentBalance(), 4),
                '0.00',
            );

        $cashBalance = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->where('type', FinancialAccountType::Cash)
            ->with('balance')
            ->get()
            ->reduce(
                fn (string $carry, FinancialAccount $account): string => bcadd($carry, $account->getCurrentBalance(), 4),
                '0.00',
            );

        $receivablesOutstanding = Receivable::query()
            ->where('company_id', $company->getKey())
            ->whereIn('status', [ReceivableStatus::Open, ReceivableStatus::Partial])
            ->sum('outstanding_amount');

        $payablesOutstanding = PayableInstallment::query()
            ->where('company_id', $company->getKey())
            ->whereIn('status', [PayableStatus::Open, PayableStatus::Partial])
            ->where('outstanding_amount', '>', 0)
            ->sum('outstanding_amount');

        $payablesOverdue = PayableInstallment::query()
            ->where('company_id', $company->getKey())
            ->whereIn('status', [PayableStatus::Open, PayableStatus::Partial])
            ->whereDate('due_date', '<', CompanyDateTime::nowLocal($company)->toDateString())
            ->where('outstanding_amount', '>', 0)
            ->sum('outstanding_amount');

        $upcomingPayables = PayableInstallment::query()
            ->where('company_id', $company->getKey())
            ->whereIn('status', [PayableStatus::Open, PayableStatus::Partial])
            ->whereBetween('due_date', [
                CompanyDateTime::nowLocal($company)->toDateString(),
                CompanyDateTime::nowLocal($company)->addDays(7)->toDateString(),
            ])
            ->where('outstanding_amount', '>', 0)
            ->sum('outstanding_amount');

        $monthStart = CompanyDateTime::nowLocal($company)->startOfMonth();
        $monthEnd = CompanyDateTime::nowLocal($company)->endOfMonth();

        $monthlyExpenses = Payable::query()
            ->where('company_id', $company->getKey())
            ->whereNotIn('status', [PayableStatus::Draft, PayableStatus::Cancelled])
            ->whereBetween('competence_date', [$monthStart->toDateString(), $monthEnd->toDateString()])
            ->sum('total_amount');

        return new FinancialOverviewSummary(
            totalBalance: $this->formatAmount($totalBalance),
            cashBalance: $this->formatAmount($cashBalance),
            received: $cashFlow->inflows,
            paid: $cashFlow->outflows,
            netFlow: $cashFlow->netFlow,
            receivablesOutstanding: $this->formatAmount($receivablesOutstanding),
            payablesOutstanding: $this->formatAmount($payablesOutstanding),
            payablesOverdue: $this->formatAmount($payablesOverdue),
            managerialResult: $dre->operationalResult,
            monthlyExpenses: $this->formatAmount($monthlyExpenses),
            upcomingPayables: $this->formatAmount($upcomingPayables),
        );
    }

    /**
     * @return Collection<int, ExpenseByCategoryRow>
     */
    public function expensesByCategory(
        Company $company,
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal,
    ): Collection {
        $startDate = $startLocal->toDateString();
        $endDate = $endLocal->toDateString();

        $categories = ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $payables = Payable::query()
            ->where('company_id', $company->getKey())
            ->whereNotIn('status', [PayableStatus::Draft, PayableStatus::Cancelled])
            ->whereBetween('competence_date', [$startDate, $endDate])
            ->with(['expenseCategory', 'installments'])
            ->get();

        $grouped = $payables->groupBy('expense_category_id');

        $rows = $categories->map(function (ExpenseCategory $category) use ($grouped): ExpenseByCategoryRow {
            /** @var Collection<int, Payable> $categoryPayables */
            $categoryPayables = $grouped->get($category->getKey(), collect());

            $competenceAmount = $categoryPayables->reduce(
                fn (string $carry, Payable $payable): string => bcadd($carry, (string) $payable->total_amount, 4),
                '0.00',
            );

            $paidAmount = '0.00';
            $outstandingAmount = '0.00';

            foreach ($categoryPayables as $payable) {
                foreach ($payable->installments as $installment) {
                    $paidAmount = bcadd($paidAmount, (string) $installment->settled_principal_amount, 4);
                    $outstandingAmount = bcadd($outstandingAmount, (string) $installment->outstanding_amount, 4);
                }
            }

            return new ExpenseByCategoryRow(
                categoryId: (int) $category->getKey(),
                categoryName: $category->name,
                competenceAmount: $this->formatAmount($competenceAmount),
                paidAmount: $this->formatAmount($paidAmount),
                outstandingAmount: $this->formatAmount($outstandingAmount),
                percentage: '0.00',
            );
        })->filter(fn (ExpenseByCategoryRow $row): bool => bccomp($row->competenceAmount, '0', 2) > 0);

        $totalCompetence = $rows->reduce(
            fn (string $carry, ExpenseByCategoryRow $row): string => bcadd($carry, $row->competenceAmount, 4),
            '0.00',
        );

        return $rows->map(function (ExpenseByCategoryRow $row) use ($totalCompetence): ExpenseByCategoryRow {
            $percentage = bccomp($totalCompetence, '0', 2) === 0
                ? '0.00'
                : bcmul(bcdiv($row->competenceAmount, $totalCompetence, 6), '100', 2);

            return new ExpenseByCategoryRow(
                categoryId: $row->categoryId,
                categoryName: $row->categoryName,
                competenceAmount: $row->competenceAmount,
                paidAmount: $row->paidAmount,
                outstandingAmount: $row->outstandingAmount,
                percentage: $percentage,
            );
        })->values();
    }

    protected function formatAmount(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }
}
