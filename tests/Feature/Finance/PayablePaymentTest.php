<?php

namespace Tests\Feature\Finance;

use App\DataTransferObjects\Financial\PayablePaymentData;
use App\Enums\CompanyRole;
use App\Enums\FinancialTransactionDirection;
use App\Enums\PayablePaymentStatus;
use App\Enums\PayableStatus;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\FinancialTransaction;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\PayablePayment;
use App\Models\User;
use App\Services\Financial\PayableInstallmentCalculator;
use App\Services\Financial\PayablePaymentService;
use App\Services\Financial\PayableService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class PayablePaymentTest extends TestCase
{
    use CreatesFinanceFixtures;

    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createCompany();
        $this->user = $this->createCompanyUser($this->company, [], CompanyRole::CompanyAdmin);
    }

    public function test_cash_outflow_formula_with_charges_and_discount(): void
    {
        $calculator = app(PayableInstallmentCalculator::class);

        $cashOutflow = $calculator->calculateCashOutflow(
            settledPrincipalAmount: '100.00',
            interestAmount: '2.00',
            penaltyAmount: '1.00',
            feeAmount: '0.50',
            discountAmount: '5.00',
        );

        $this->assertSame('98.50', $cashOutflow);
    }

    public function test_payment_creates_outbound_transaction_and_reduces_balance(): void
    {
        [$payable, $installment] = $this->createOpenPayable('100.00');
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '200.00', $this->user);

        $payment = app(PayablePaymentService::class)->record(
            $this->company,
            $installment,
            $account,
            $this->user,
            new PayablePaymentData(
                settledPrincipalAmount: '100.00',
                method: PaymentMethod::Pix,
                paidAt: now(),
            ),
        );

        $account->refresh()->load('balance');

        $this->assertSame('100.00', (string) $payment->cash_outflow_amount);
        $this->assertSame(PayablePaymentStatus::Confirmed, $payment->status);
        $this->assertSame('100.00', $account->getCurrentBalance());
        $this->assertDatabaseHas('financial_transactions', [
            'reference_key' => $payment->ledgerReferenceKey(),
            'direction' => FinancialTransactionDirection::Outbound->value,
            'amount' => '100.00',
        ]);
    }

    public function test_payment_example_with_all_adjustments(): void
    {
        [$payable, $installment] = $this->createOpenPayable('100.00');
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '200.00', $this->user);

        $payment = app(PayablePaymentService::class)->record(
            $this->company,
            $installment,
            $account,
            $this->user,
            new PayablePaymentData(
                settledPrincipalAmount: '100.00',
                method: PaymentMethod::BankTransfer,
                paidAt: now(),
                interestAmount: '2.00',
                penaltyAmount: '1.00',
                feeAmount: '0.50',
                discountAmount: '5.00',
            ),
        );

        $payable->refresh();
        $installment->refresh();
        $account->refresh()->load('balance');

        $this->assertSame('98.50', (string) $payment->cash_outflow_amount);
        $this->assertSame(PayableStatus::Paid, $payable->status);
        $this->assertSame(PayableStatus::Paid, $installment->status);
        $this->assertSame('101.50', $account->getCurrentBalance());
    }

    public function test_partial_payment_updates_status(): void
    {
        [$payable, $installment] = $this->createOpenPayable('100.00');
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '200.00', $this->user);

        app(PayablePaymentService::class)->record(
            $this->company,
            $installment,
            $account,
            $this->user,
            new PayablePaymentData('40.00', PaymentMethod::Pix, now()),
        );

        $payable->refresh();
        $installment->refresh();

        $this->assertSame(PayableStatus::Partial, $payable->status);
        $this->assertSame(PayableStatus::Partial, $installment->status);
        $this->assertSame('60.00', (string) $installment->outstanding_amount);
    }

    public function test_rejects_payment_above_installment_outstanding(): void
    {
        [$payable, $installment] = $this->createOpenPayable('100.00');
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '200.00', $this->user);

        $this->expectException(ValidationException::class);

        app(PayablePaymentService::class)->record(
            $this->company,
            $installment,
            $account,
            $this->user,
            new PayablePaymentData('100.01', PaymentMethod::Pix, now()),
        );
    }

    public function test_rejects_payment_when_account_has_insufficient_balance(): void
    {
        [$payable, $installment] = $this->createOpenPayable('100.00');
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '50.00', $this->user);

        $this->expectException(ValidationException::class);

        app(PayablePaymentService::class)->record(
            $this->company,
            $installment,
            $account,
            $this->user,
            new PayablePaymentData('100.00', PaymentMethod::Pix, now()),
        );
    }

    public function test_cancelled_payment_reverses_transaction_and_recalculates_installment(): void
    {
        [$payable, $installment] = $this->createOpenPayable('100.00');
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '200.00', $this->user);

        $payment = app(PayablePaymentService::class)->record(
            $this->company,
            $installment,
            $account,
            $this->user,
            new PayablePaymentData('100.00', PaymentMethod::Pix, now()),
        );

        app(PayablePaymentService::class)->cancel(
            $this->company,
            $payment,
            $this->user,
            'Erro de lançamento',
        );

        $payment->refresh();
        $installment->refresh();
        $payable->refresh();
        $account->refresh()->load('balance');

        $this->assertSame(PayablePaymentStatus::Cancelled, $payment->status);
        $this->assertDatabaseHas('payable_payments', ['id' => $payment->getKey()]);
        $this->assertSame('200.00', $account->getCurrentBalance());
        $this->assertSame(PayableStatus::Open, $payable->status);
        $this->assertSame('100.00', (string) $installment->outstanding_amount);
        $originalTransaction = FinancialTransaction::query()
            ->where('reference_key', $payment->ledgerReferenceKey())
            ->firstOrFail();

        $this->assertTrue(
            FinancialTransaction::query()
                ->where('reference_key', "financial-transaction:{$originalTransaction->getKey()}:reversal")
                ->exists(),
        );
    }

    public function test_quick_expense_paid_now_marks_payable_as_paid(): void
    {
        $category = $this->createOperationalCategory($this->company);
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '500.00', $this->user);

        $payable = app(PayableService::class)->createQuickExpense(
            $this->company,
            $category,
            [
                'description' => 'Material de limpeza',
                'total_amount' => '45.00',
                'due_date' => now(),
                'paid_now' => true,
                'method' => PaymentMethod::Cash,
                'paid_at' => now(),
            ],
            $this->user,
            null,
            $account,
        );

        $this->assertSame(PayableStatus::Paid, $payable->status);
        $this->assertSame(1, PayablePayment::query()->where('payable_id', $payable->getKey())->count());
        $this->assertDatabaseHas('financial_transactions', [
            'company_id' => $this->company->getKey(),
            'type' => 'expense_payment',
            'amount' => '45.00',
        ]);
    }

    /**
     * @return array{0: Payable, 1: PayableInstallment}
     */
    protected function createOpenPayable(string $amount): array
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

        return [$payable, $installment];
    }
}
