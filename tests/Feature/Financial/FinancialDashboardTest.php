<?php

namespace Tests\Feature\Financial;

use App\Enums\CompanyRole;
use App\Enums\FinancialDashboardPeriod;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Financial\FinancialDashboardAggregator;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class FinancialDashboardTest extends TestCase
{
    use CreatesSchedulingFixtures;

    protected Company $company;

    protected User $admin;

    protected FinancialDashboardAggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-25 14:00:00');

        $this->company = $this->createSchedulingCompany();
        $this->admin = $this->createCompanyUser($this->company, [], CompanyRole::CompanyAdmin);
        $this->aggregator = app(FinancialDashboardAggregator::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_aggregator_sums_completed_attendance_metrics(): void
    {
        $this->createCompletedAttendance([
            'final_amount' => '200.00',
            'commission_amount' => '30.00',
            'materials_reserve_amount' => '20.00',
            'business_reserve_amount' => '20.00',
            'owner_allocation_amount' => '130.00',
            'actual_material_cost' => '15.00',
            'payment_fee_amount' => '5.00',
            'operational_result' => '150.00',
            'completed_at' => now(),
        ], paidAmount: '200.00', outstandingAmount: '0.00');

        [$start, $end] = $this->aggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Today,
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('200.00', $summary->completedRevenue);
        $this->assertSame('200.00', $summary->received);
        $this->assertSame('0.00', $summary->outstanding);
        $this->assertSame('15.00', $summary->materialCost);
        $this->assertSame('30.00', $summary->commissions);
        $this->assertSame('20.00', $summary->materialReserve);
        $this->assertSame('20.00', $summary->businessReserve);
        $this->assertSame('130.00', $summary->ownerAllocation);
        $this->assertSame('5.00', $summary->paymentFees);
        $this->assertSame('150.00', $summary->operationalResult);
    }

    public function test_aggregator_excludes_attendances_outside_period(): void
    {
        $this->createCompletedAttendance([
            'final_amount' => '100.00',
            'completed_at' => now()->subDays(10),
        ], paidAmount: '100.00', outstandingAmount: '0.00');

        [$start, $end] = $this->aggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Today,
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('0.00', $summary->completedRevenue);
        $this->assertSame('0.00', $summary->received);
    }

    public function test_aggregator_scopes_data_to_tenant(): void
    {
        $otherCompany = $this->createSchedulingCompany();

        $this->createCompletedAttendance([
            'final_amount' => '100.00',
            'completed_at' => now(),
        ], paidAmount: '100.00', outstandingAmount: '0.00');

        $this->createCompletedAttendance([
            'final_amount' => '500.00',
            'completed_at' => now(),
        ], paidAmount: '500.00', outstandingAmount: '0.00', company: $otherCompany);

        [$start, $end] = $this->aggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Today,
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('100.00', $summary->completedRevenue);
        $this->assertSame('100.00', $summary->received);
    }

    public function test_aggregator_sums_partial_and_unpaid_balances(): void
    {
        $this->createCompletedAttendance([
            'final_amount' => '100.00',
            'completed_at' => now(),
        ], paidAmount: '40.00', outstandingAmount: '60.00');

        $this->createCompletedAttendance([
            'final_amount' => '80.00',
            'completed_at' => now(),
        ], paidAmount: '0.00', outstandingAmount: '80.00');

        [$start, $end] = $this->aggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Today,
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('180.00', $summary->completedRevenue);
        $this->assertSame('40.00', $summary->received);
        $this->assertSame('140.00', $summary->outstanding);
    }

    public function test_custom_period_filter_uses_provided_dates(): void
    {
        $this->createCompletedAttendance([
            'final_amount' => '120.00',
            'completed_at' => CompanyDateTime::localToUtc(
                $this->company,
                CarbonImmutable::parse('2026-07-10 10:00:00', CompanyDateTime::timezone($this->company)),
            ),
        ], paidAmount: '120.00', outstandingAmount: '0.00');

        [$start, $end] = $this->aggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Custom,
            '2026-07-01',
            '2026-07-15',
        );

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('120.00', $summary->completedRevenue);
    }

    public function test_manager_can_access_financial_dashboard_page(): void
    {
        $manager = $this->createCompanyUser($this->company, [], CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $this->company);

        $this->get(route('filament.app.pages.dashboard-financeiro', ['tenant' => $this->company]))
            ->assertOk();
    }

    public function test_employee_cannot_access_financial_dashboard_page(): void
    {
        $employee = $this->createCompanyUser($this->company, [], CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $this->company);

        $this->get(route('filament.app.pages.dashboard-financeiro', ['tenant' => $this->company]))
            ->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $attendanceAttributes
     */
    protected function createCompletedAttendance(
        array $attendanceAttributes,
        string $paidAmount,
        string $outstandingAmount,
        ?Company $company = null,
    ): Attendance {
        $company ??= $this->company;
        $setup = $this->createBookableSetup($company);

        $appointment = Appointment::factory()
            ->forCompany($company)
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
            ]);

        $attendance = Attendance::factory()
            ->forCompany($company)
            ->forAppointment($appointment)
            ->create(array_merge([
                'client_name_snapshot' => $setup['client']->name,
                'professional_name_snapshot' => $setup['professional']->name,
                'gross_amount' => $attendanceAttributes['final_amount'] ?? '100.00',
                'discount_amount' => '0.00',
            ], $attendanceAttributes));

        $receivable = new Receivable([
            'original_amount' => (string) $attendance->final_amount,
            'paid_amount' => $paidAmount,
            'outstanding_amount' => $outstandingAmount,
            'status' => bccomp($outstandingAmount, '0', 2) === 0 ? 'paid' : (bccomp($paidAmount, '0', 2) === 0 ? 'open' : 'partial'),
            'due_date' => now()->addDays(7)->toDateString(),
        ]);
        $receivable->company()->associate($company);
        $receivable->attendance()->associate($attendance);
        $receivable->client()->associate($attendance->client);
        $receivable->save();

        return $attendance->refresh();
    }
}
