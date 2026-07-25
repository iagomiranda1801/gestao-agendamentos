<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\ManagerialDreSummary;
use App\Enums\ExpenseCategoryType;
use App\Enums\PayableStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\Payable;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ManagerialDreAggregator
{
    public function aggregate(
        Company $company,
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal,
    ): ManagerialDreSummary {
        $startUtc = CompanyDateTime::localToUtc($company, $startLocal);
        $endUtc = CompanyDateTime::localToUtc($company, $endLocal);
        $startDate = $startLocal->toDateString();
        $endDate = $endLocal->toDateString();

        $attendanceStats = Attendance::query()
            ->where('company_id', $company->getKey())
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$startUtc, $endUtc])
            ->select([
                DB::raw('COALESCE(SUM(gross_amount), 0) as gross_revenue'),
                DB::raw('COALESCE(SUM(discount_amount), 0) as discounts'),
                DB::raw('COALESCE(SUM(final_amount), 0) as net_revenue'),
                DB::raw('COALESCE(SUM(actual_material_cost), 0) as material_cost'),
                DB::raw('COALESCE(SUM(commission_amount), 0) as commissions'),
                DB::raw('COALESCE(SUM(payment_fee_amount), 0) as payment_fees'),
                DB::raw('COALESCE(SUM(materials_reserve_amount), 0) as material_reserve'),
                DB::raw('COALESCE(SUM(business_reserve_amount), 0) as business_reserve'),
                DB::raw('COALESCE(SUM(owner_allocation_amount), 0) as owner_allocation'),
            ])
            ->first();

        $operationalExpenses = Payable::query()
            ->where('company_id', $company->getKey())
            ->whereIn('status', [
                PayableStatus::Open->value,
                PayableStatus::Partial->value,
                PayableStatus::Paid->value,
            ])
            ->whereDate('competence_date', '>=', $startDate)
            ->whereDate('competence_date', '<=', $endDate)
            ->whereIn('expense_category_id', ExpenseCategory::query()
                ->select('id')
                ->where('company_id', $company->getKey())
                ->where('affects_managerial_result', true)
                ->where('type', '!=', ExpenseCategoryType::StockPurchase->value))
            ->sum('total_amount');

        $netRevenue = $this->formatAmount($attendanceStats?->net_revenue);
        $materialCost = $this->formatAmount($attendanceStats?->material_cost);
        $commissions = $this->formatAmount($attendanceStats?->commissions);
        $paymentFees = $this->formatAmount($attendanceStats?->payment_fees);

        $contributionMargin = bcsub(
            bcsub(
                bcsub($netRevenue, $materialCost, 4),
                $commissions,
                4
            ),
            $paymentFees,
            2,
        );

        $operationalExpensesFormatted = $this->formatAmount($operationalExpenses);
        $operationalResult = bcsub($contributionMargin, $operationalExpensesFormatted, 2);

        return new ManagerialDreSummary(
            grossRevenue: $this->formatAmount($attendanceStats?->gross_revenue),
            discounts: $this->formatAmount($attendanceStats?->discounts),
            netRevenue: $netRevenue,
            materialCost: $materialCost,
            commissions: $commissions,
            paymentFees: $paymentFees,
            contributionMargin: $this->formatAmount($contributionMargin),
            operationalExpenses: $operationalExpensesFormatted,
            operationalResult: $this->formatAmount($operationalResult),
            materialReserve: $this->formatAmount($attendanceStats?->material_reserve),
            businessReserve: $this->formatAmount($attendanceStats?->business_reserve),
            ownerAllocation: $this->formatAmount($attendanceStats?->owner_allocation),
        );
    }

    protected function formatAmount(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }
}
