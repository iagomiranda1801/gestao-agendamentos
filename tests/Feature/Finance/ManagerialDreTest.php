<?php

namespace Tests\Feature\Finance;

use App\Enums\CompanyRole;
use App\Enums\FinancialDashboardPeriod;
use App\Enums\FinancialTransactionType;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\User;
use App\Services\Financial\FinancialDashboardAggregator;
use App\Services\Financial\FinancialLedgerService;
use App\Services\Financial\ManagerialDreAggregator;
use App\Services\Financial\PayableService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class ManagerialDreTest extends TestCase
{
    use CreatesFinanceFixtures;
    use CreatesSchedulingFixtures;

    protected Company $company;

    protected User $user;

    protected ManagerialDreAggregator $aggregator;

    protected FinancialDashboardAggregator $periodAggregator;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-25 14:00:00');

        $this->company = $this->createSchedulingCompany();
        $this->user = $this->createCompanyUser($this->company, [], CompanyRole::CompanyAdmin);
        $this->aggregator = app(ManagerialDreAggregator::class);
        $this->periodAggregator = app(FinancialDashboardAggregator::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_dre_example_with_ten_thousand_reais_in_revenue(): void
    {
        $this->createCompletedAttendance([
            'gross_amount' => '10000.00',
            'discount_amount' => '500.00',
            'final_amount' => '9500.00',
            'actual_material_cost' => '1000.00',
            'commission_amount' => '1425.00',
            'payment_fee_amount' => '200.00',
            'materials_reserve_amount' => '950.00',
            'business_reserve_amount' => '950.00',
            'owner_allocation_amount' => '6175.00',
            'operational_result' => '6875.00',
            'completed_at' => now(),
        ]);

        $category = $this->createOperationalCategory($this->company);
        $payableService = app(PayableService::class);

        $payable = $payableService->createDraft($this->company, $category, [
            'description' => 'Despesas operacionais do mês',
            'total_amount' => '2000.00',
            'competence_date' => now()->toDateString(),
        ], $this->user);

        $payableService->createInstallments($this->company, $payable, [
            ['due_date' => now()->addDays(10), 'amount' => '2000.00'],
        ]);

        $payableService->launch($this->company, $payable->refresh());

        [$start, $end] = $this->periodAggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Today,
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('10000.00', $summary->grossRevenue);
        $this->assertSame('500.00', $summary->discounts);
        $this->assertSame('9500.00', $summary->netRevenue);
        $this->assertSame('1000.00', $summary->materialCost);
        $this->assertSame('1425.00', $summary->commissions);
        $this->assertSame('200.00', $summary->paymentFees);
        $this->assertSame('6875.00', $summary->contributionMargin);
        $this->assertSame('2000.00', $summary->operationalExpenses);
        $this->assertSame('4875.00', $summary->operationalResult);
        $this->assertSame('950.00', $summary->materialReserve);
        $this->assertSame('950.00', $summary->businessReserve);
        $this->assertSame('6175.00', $summary->ownerAllocation);
    }

    public function test_dre_excludes_attendances_outside_period(): void
    {
        $this->createCompletedAttendance([
            'gross_amount' => '500.00',
            'discount_amount' => '0.00',
            'final_amount' => '500.00',
            'completed_at' => CompanyDateTime::localToUtc(
                $this->company,
                CarbonImmutable::parse('2026-06-01 10:00:00', CompanyDateTime::timezone($this->company)),
            ),
        ]);

        [$start, $end] = $this->periodAggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Custom,
            '2026-07-01',
            '2026-07-31',
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('0.00', $summary->grossRevenue);
        $this->assertSame('0.00', $summary->netRevenue);
    }

    public function test_stock_purchase_payable_does_not_affect_operational_expenses(): void
    {
        $this->createCompletedAttendance([
            'gross_amount' => '1000.00',
            'discount_amount' => '0.00',
            'final_amount' => '1000.00',
            'actual_material_cost' => '100.00',
            'commission_amount' => '150.00',
            'payment_fee_amount' => '20.00',
            'completed_at' => now(),
        ]);

        $stockCategory = $this->createStockPurchaseCategory($this->company);
        $payableService = app(PayableService::class);

        $payable = $payableService->createDraft($this->company, $stockCategory, [
            'description' => 'Compra de estoque',
            'total_amount' => '3000.00',
            'competence_date' => now()->toDateString(),
        ], $this->user);

        $payableService->createInstallments($this->company, $payable, [
            ['due_date' => now()->addDays(10), 'amount' => '3000.00'],
        ]);

        $payableService->launch($this->company, $payable->refresh());

        [$start, $end] = $this->periodAggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Today,
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('0.00', $summary->operationalExpenses);
        $this->assertSame('730.00', $summary->contributionMargin);
    }

    public function test_cash_adjustments_do_not_affect_dre(): void
    {
        $this->createCompletedAttendance([
            'gross_amount' => '800.00',
            'discount_amount' => '0.00',
            'final_amount' => '800.00',
            'actual_material_cost' => '50.00',
            'commission_amount' => '120.00',
            'payment_fee_amount' => '10.00',
            'completed_at' => now(),
        ]);

        $account = $this->createCashAccount($this->company);
        $ledger = app(FinancialLedgerService::class);

        $ledger->postInbound(
            $this->company,
            $account,
            '100.00',
            FinancialTransactionType::CashReinforcement,
            now(),
            'Reforço de caixa',
            'reinforcement-test',
            null,
            $this->user,
        );

        $ledger->postOutbound(
            $this->company,
            $account,
            '40.00',
            FinancialTransactionType::CashWithdrawal,
            now(),
            'Sangria de caixa',
            'withdrawal-test',
            null,
            $this->user,
        );

        [$start, $end] = $this->periodAggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Today,
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('620.00', $summary->contributionMargin);
        $this->assertSame('0.00', $summary->operationalExpenses);
        $this->assertSame('620.00', $summary->operationalResult);
    }

    public function test_aggregator_scopes_data_to_tenant(): void
    {
        $otherCompany = $this->createSchedulingCompany();

        $this->createCompletedAttendance([
            'gross_amount' => '1000.00',
            'discount_amount' => '0.00',
            'final_amount' => '1000.00',
            'completed_at' => now(),
        ]);

        $this->createCompletedAttendance([
            'gross_amount' => '5000.00',
            'discount_amount' => '0.00',
            'final_amount' => '5000.00',
            'completed_at' => now(),
        ], company: $otherCompany);

        [$start, $end] = $this->periodAggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Today,
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('1000.00', $summary->grossRevenue);
    }

    public function test_competence_filter_excludes_payables_outside_period(): void
    {
        $this->createCompletedAttendance([
            'gross_amount' => '1000.00',
            'discount_amount' => '0.00',
            'final_amount' => '1000.00',
            'completed_at' => now(),
        ]);

        $category = $this->createOperationalCategory($this->company);
        $payableService = app(PayableService::class);

        $payable = $payableService->createDraft($this->company, $category, [
            'description' => 'Despesa antiga',
            'total_amount' => '500.00',
            'competence_date' => '2026-06-01',
        ], $this->user);

        $payableService->createInstallments($this->company, $payable, [
            ['due_date' => '2026-06-10', 'amount' => '500.00'],
        ]);

        $payableService->launch($this->company, $payable->refresh());

        [$start, $end] = $this->periodAggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Custom,
            '2026-07-01',
            '2026-07-31',
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('0.00', $summary->operationalExpenses);
    }

    /**
     * @param  array<string, mixed>  $attendanceAttributes
     */
    protected function createCompletedAttendance(array $attendanceAttributes, ?Company $company = null): Attendance
    {
        $company ??= $this->company;
        $setup = $this->createBookableSetup($company);

        $appointment = Appointment::factory()
            ->forCompany($company)
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
            ]);

        return Attendance::factory()
            ->forCompany($company)
            ->forAppointment($appointment)
            ->create(array_merge([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'client_name_snapshot' => $setup['client']->name,
                'professional_name_snapshot' => $setup['professional']->name,
            ], $attendanceAttributes));
    }
}
