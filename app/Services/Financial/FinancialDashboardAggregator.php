<?php

namespace App\Services\Financial;

use App\DataTransferObjects\Financial\FinancialDashboardSummary;
use App\Enums\FinancialDashboardPeriod;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Receivable;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class FinancialDashboardAggregator
{
    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function resolvePeriodBounds(
        Company $company,
        FinancialDashboardPeriod $period,
        ?string $startDate = null,
        ?string $endDate = null,
    ): array {
        $timezone = CompanyDateTime::timezone($company);
        $now = CompanyDateTime::nowLocal($company);

        return match ($period) {
            FinancialDashboardPeriod::Today => [
                $now->startOfDay(),
                $now->endOfDay(),
            ],
            FinancialDashboardPeriod::Week => [
                $now->startOfWeek(),
                $now->endOfWeek(),
            ],
            FinancialDashboardPeriod::Month => [
                $now->startOfMonth(),
                $now->endOfMonth(),
            ],
            FinancialDashboardPeriod::Custom => [
                CarbonImmutable::parse((string) $startDate, $timezone)->startOfDay(),
                CarbonImmutable::parse((string) $endDate, $timezone)->endOfDay(),
            ],
        };
    }

    public function aggregate(
        Company $company,
        CarbonImmutable $startLocal,
        CarbonImmutable $endLocal,
    ): FinancialDashboardSummary {
        $startUtc = CompanyDateTime::localToUtc($company, $startLocal);
        $endUtc = CompanyDateTime::localToUtc($company, $endLocal);

        $attendanceStats = Attendance::query()
            ->where('company_id', $company->getKey())
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$startUtc, $endUtc])
            ->select([
                DB::raw('COALESCE(SUM(final_amount), 0) as completed_revenue'),
                DB::raw('COALESCE(SUM(actual_material_cost), 0) as material_cost'),
                DB::raw('COALESCE(SUM(commission_amount), 0) as commissions'),
                DB::raw('COALESCE(SUM(materials_reserve_amount), 0) as material_reserve'),
                DB::raw('COALESCE(SUM(business_reserve_amount), 0) as business_reserve'),
                DB::raw('COALESCE(SUM(owner_allocation_amount), 0) as owner_allocation'),
                DB::raw('COALESCE(SUM(payment_fee_amount), 0) as payment_fees'),
                DB::raw('COALESCE(SUM(operational_result), 0) as operational_result'),
            ])
            ->first();

        $receivableStats = Receivable::query()
            ->where('company_id', $company->getKey())
            ->whereHas('attendance', fn ($query) => $query
                ->whereNotNull('completed_at')
                ->whereBetween('completed_at', [$startUtc, $endUtc]))
            ->select([
                DB::raw('COALESCE(SUM(paid_amount), 0) as received'),
                DB::raw('COALESCE(SUM(outstanding_amount), 0) as outstanding'),
            ])
            ->first();

        return new FinancialDashboardSummary(
            completedRevenue: $this->formatAmount($attendanceStats?->completed_revenue),
            received: $this->formatAmount($receivableStats?->received),
            outstanding: $this->formatAmount($receivableStats?->outstanding),
            materialCost: $this->formatAmount($attendanceStats?->material_cost),
            commissions: $this->formatAmount($attendanceStats?->commissions),
            materialReserve: $this->formatAmount($attendanceStats?->material_reserve),
            businessReserve: $this->formatAmount($attendanceStats?->business_reserve),
            ownerAllocation: $this->formatAmount($attendanceStats?->owner_allocation),
            paymentFees: $this->formatAmount($attendanceStats?->payment_fees),
            operationalResult: $this->formatAmount($attendanceStats?->operational_result),
        );
    }

    protected function formatAmount(mixed $amount): string
    {
        return number_format((float) ($amount ?? 0), 2, '.', '');
    }
}
