<?php

namespace Tests\Feature\Finance;

use App\DataTransferObjects\Financial\FinancialTransferData;
use App\DataTransferObjects\Financial\PayablePaymentData;
use App\Enums\CompanyRole;
use App\Enums\FinancialDashboardPeriod;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\PayablePayment;
use App\Models\User;
use App\Services\Financial\FinancialCashFlowAggregator;
use App\Services\Financial\FinancialDashboardAggregator;
use App\Services\Financial\FinancialLedgerService;
use App\Services\Financial\FinancialTransferService;
use App\Services\Financial\PayablePaymentService;
use App\Services\Financial\PayableService;
use Carbon\CarbonImmutable;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class CashFlowTest extends TestCase
{
    use CreatesFinanceFixtures;

    protected Company $company;

    protected User $user;

    protected FinancialCashFlowAggregator $aggregator;

    protected FinancialDashboardAggregator $periodAggregator;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-07-25 14:00:00');

        $this->company = $this->createCompany();
        $this->user = $this->createCompanyUser($this->company, [], CompanyRole::CompanyAdmin);
        $this->aggregator = app(FinancialCashFlowAggregator::class);
        $this->periodAggregator = app(FinancialDashboardAggregator::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_confirmed_inbound_appears_in_period(): void
    {
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '250.00', $this->user);

        [$start, $end] = $this->periodBounds();

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('250.00', $summary->inflows);
        $this->assertSame('0.00', $summary->outflows);
        $this->assertSame('250.00', $summary->netFlow);
    }

    public function test_confirmed_outbound_appears_in_period(): void
    {
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '300.00', $this->user);

        [$payable, $installment] = $this->createOpenPayable('80.00', $account);

        [$start, $end] = $this->periodBounds();

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('300.00', $summary->inflows);
        $this->assertSame('80.00', $summary->outflows);
        $this->assertSame('220.00', $summary->netFlow);
    }

    public function test_cancelled_payment_does_not_remain_in_net_flow(): void
    {
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '300.00', $this->user);

        [$payable, $installment] = $this->createOpenPayable('80.00');

        app(PayablePaymentService::class)->record(
            $this->company,
            $installment,
            $account,
            $this->user,
            new PayablePaymentData('80.00', PaymentMethod::Pix, now()),
        );

        $payment = PayablePayment::query()->where('payable_id', $payable->getKey())->firstOrFail();

        app(PayablePaymentService::class)->cancel($this->company, $payment, $this->user, 'Estorno de teste');

        [$start, $end] = $this->periodBounds();

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('300.00', $summary->inflows);
        $this->assertSame('0.00', $summary->outflows);
        $this->assertSame('300.00', $summary->netFlow);
    }

    public function test_transfer_cancels_out_in_consolidated_view(): void
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

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('500.00', $summary->inflows);
        $this->assertSame('0.00', $summary->outflows);
        $this->assertSame('500.00', $summary->netFlow);
    }

    public function test_account_filter_includes_transfer_on_specific_account(): void
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

        $fromSummary = $this->aggregator->aggregate($this->company, $start, $end, [$from->getKey()]);
        $toSummary = $this->aggregator->aggregate($this->company, $start, $end, [$to->getKey()]);

        $this->assertSame('500.00', $fromSummary->inflows);
        $this->assertSame('150.00', $fromSummary->outflows);
        $this->assertSame('150.00', $toSummary->inflows);
        $this->assertSame('0.00', $toSummary->outflows);
        $this->assertSame('0.00', $toSummary->initialBalance);
        $this->assertSame('150.00', $toSummary->finalBalance);
    }

    public function test_period_filter_excludes_movements_outside_range(): void
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

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('0.00', $summary->inflows);
        $this->assertSame('0.00', $summary->outflows);
        $this->assertSame('400.00', $summary->initialBalance);
        $this->assertSame('400.00', $summary->finalBalance);
    }

    public function test_aggregator_scopes_data_to_tenant(): void
    {
        $otherCompany = $this->createCompany();
        $account = $this->createFinancialAccount($this->company);
        $otherAccount = $this->createFinancialAccount($otherCompany);

        $this->fundAccount($this->company, $account, '100.00', $this->user);
        $this->fundAccount($otherCompany, $otherAccount, '900.00', $this->createCompanyUser($otherCompany, [], CompanyRole::CompanyAdmin));

        [$start, $end] = $this->periodBounds();

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('100.00', $summary->inflows);
        $this->assertSame('100.00', $summary->finalBalance);
    }

    public function test_initial_and_final_balances_are_consistent(): void
    {
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '500.00', $this->user);
        [$payable, $installment] = $this->createOpenPayable('125.00', $account);

        [$start, $end] = $this->periodBounds();

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('0.00', $summary->initialBalance);
        $this->assertSame('375.00', $summary->finalBalance);
        $this->assertSame(
            $summary->finalBalance,
            bcadd(bcadd($summary->initialBalance, $summary->inflows, 4), bcmul($summary->outflows, '-1', 4), 2),
        );
    }

    public function test_future_movements_do_not_enter_current_period(): void
    {
        $account = $this->createFinancialAccount($this->company);

        app(FinancialLedgerService::class)->postInbound(
            $this->company,
            $account,
            '999.00',
            FinancialTransactionType::OpeningBalance,
            CarbonImmutable::parse('2026-08-01 10:00:00'),
            'Saldo futuro',
            'future-balance:'.$account->getKey(),
            null,
            $this->user,
        );

        [$start, $end] = $this->periodBounds();

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('0.00', $summary->inflows);
        $this->assertSame('0.00', $summary->outflows);
        $this->assertSame('0.00', $summary->initialBalance);
        $this->assertSame('0.00', $summary->finalBalance);
    }

    public function test_open_payables_do_not_count_as_realized_outflow(): void
    {
        $this->createOpenPayable('250.00');

        [$start, $end] = $this->periodBounds();

        $summary = $this->aggregator->aggregate($this->company, $start, $end);

        $this->assertSame('0.00', $summary->inflows);
        $this->assertSame('0.00', $summary->outflows);
        $this->assertSame('0.00', $summary->netFlow);
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
