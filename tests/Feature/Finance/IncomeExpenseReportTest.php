<?php

namespace Tests\Feature\Finance;

use App\DataTransferObjects\Financial\FinancialTransferData;
use App\DataTransferObjects\Financial\PayablePaymentData;
use App\Enums\CompanyModule;
use App\Enums\CompanyRole;
use App\Enums\FinancialDashboardPeriod;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Filament\App\Pages\IncomeExpenseReportPage;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\User;
use App\Services\Financial\FinancialDashboardAggregator;
use App\Services\Financial\FinancialLedgerService;
use App\Services\Financial\FinancialTransferService;
use App\Services\Financial\IncomeExpenseReportAggregator;
use App\Services\Financial\IncomeExpenseReportExporter;
use App\Services\Financial\PayablePaymentService;
use App\Services\Financial\PayableService;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class IncomeExpenseReportTest extends TestCase
{
    use CreatesFinanceFixtures;

    protected Company $company;

    protected User $user;

    protected IncomeExpenseReportAggregator $aggregator;

    protected FinancialDashboardAggregator $periodAggregator;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-25 14:00:00');

        $this->company = $this->createCompany();
        $this->user = $this->createCompanyUser($this->company, [], CompanyRole::CompanyAdmin);
        $this->aggregator = app(IncomeExpenseReportAggregator::class);
        $this->periodAggregator = app(FinancialDashboardAggregator::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_inbound_and_outbound_in_period_appear_in_totals(): void
    {
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '300.00', $this->user);
        $this->createOpenPayable('80.00', $account);

        [$start, $end] = $this->periodBounds();
        $report = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('300.00', $report->incomeTotal);
        $this->assertSame('80.00', $report->expenseTotal);
        $this->assertSame('220.00', $report->balance);
        $this->assertCount(2, $report->rows);
    }

    public function test_transfer_is_excluded_from_consolidated_report(): void
    {
        $from = $this->createFinancialAccount($this->company, ['name' => 'Origem']);
        $to = $this->createFinancialAccount($this->company, ['name' => 'Destino']);
        $this->fundAccount($this->company, $from, '500.00', $this->user);

        app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $from->getKey(),
                toFinancialAccountId: $to->getKey(),
                amount: '150.00',
                occurredAt: now(),
                description: 'Transferência interna',
            ),
        );

        [$start, $end] = $this->periodBounds();
        $report = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('500.00', $report->incomeTotal);
        $this->assertSame('0.00', $report->expenseTotal);
        $this->assertTrue($report->rows->every(fn ($row): bool => ! str_contains($row->typeLabel, 'Transferência')));
    }

    public function test_movements_outside_period_are_excluded(): void
    {
        $account = $this->createFinancialAccount($this->company);

        app(FinancialLedgerService::class)->postInbound(
            $this->company,
            $account,
            '400.00',
            FinancialTransactionType::OpeningBalance,
            CarbonImmutable::parse('2026-07-10 10:00:00'),
            'Saldo antigo',
            'old-balance:'.$account->getKey(),
            null,
            $this->user,
        );

        [$start, $end] = $this->periodAggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Custom,
            '2026-07-20',
            '2026-07-25',
        );

        $report = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('0.00', $report->incomeTotal);
        $this->assertSame('0.00', $report->expenseTotal);
        $this->assertCount(0, $report->rows);
    }

    public function test_page_is_accessible_with_finance_module(): void
    {
        $this->authenticateForAppTenant($this->user, $this->company);

        Livewire::test(IncomeExpenseReportPage::class)
            ->assertSuccessful()
            ->assertSee('Receitas e gastos');
    }

    public function test_page_is_forbidden_without_finance_permission(): void
    {
        $employee = $this->createCompanyUser($this->company, [], CompanyRole::Employee);
        $this->authenticateForAppTenant($employee, $this->company);

        Livewire::test(IncomeExpenseReportPage::class)
            ->assertForbidden();
    }

    public function test_page_is_forbidden_without_finance_module(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);
        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(IncomeExpenseReportPage::class)
            ->assertForbidden();
    }

    public function test_excel_export_downloads_xlsx(): void
    {
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '120.00', $this->user);

        [$start, $end] = $this->periodBounds();
        $response = app(IncomeExpenseReportExporter::class)->excel($this->company, $start, $end);

        $this->assertSame(200, $response->getStatusCode());
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.xlsx', $disposition);
        $this->assertGreaterThan(0, filesize($response->getFile()->getPathname()));
    }

    public function test_pdf_export_downloads_pdf(): void
    {
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '120.00', $this->user);

        [$start, $end] = $this->periodBounds();
        $response = app(IncomeExpenseReportExporter::class)->pdf($this->company, $start, $end);

        $this->assertSame(200, $response->getStatusCode());
        $disposition = (string) $response->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('.pdf', $disposition);
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function periodBounds(): array
    {
        return $this->periodAggregator->resolvePeriodBounds(
            $this->company,
            FinancialDashboardPeriod::Today,
        );
    }

    /**
     * @return array{0: Payable, 1: PayableInstallment}
     */
    protected function createOpenPayable(string $amount, ?FinancialAccount $account = null): array
    {
        $category = $this->createOperationalCategory($this->company);
        $service = app(PayableService::class);

        $payable = $service->createDraft($this->company, $category, [
            'description' => 'Despesa teste',
            'total_amount' => $amount,
        ], $this->user);

        $service->createInstallments($this->company, $payable, [
            ['due_date' => now()->addDays(10), 'amount' => $amount],
        ]);

        $payable = $service->launch($this->company, $payable->refresh());
        $installment = $payable->installments()->firstOrFail();

        if ($account !== null) {
            app(PayablePaymentService::class)->record(
                $this->company,
                $installment,
                $account,
                $this->user,
                new PayablePaymentData($amount, PaymentMethod::Pix, now()),
            );
        }

        return [$payable->refresh(), $installment->refresh()];
    }
}
