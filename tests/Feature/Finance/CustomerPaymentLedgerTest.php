<?php

namespace Tests\Feature\Finance;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\CompanyRole;
use App\Enums\FinancialTransactionDirection;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReceivableStatus;
use App\Models\Company;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Financial\ReceivableService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class CustomerPaymentLedgerTest extends TestCase
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

    public function test_payment_creates_inbound_financial_transaction(): void
    {
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '100.00');
        $account = $this->createFinancialAccount($this->company);

        $payment = app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '0.00', PaymentMethod::Pix, now(), $account->getKey()),
            $this->user,
        );

        $this->assertDatabaseHas('financial_transactions', [
            'reference_key' => $payment->inboundLedgerReferenceKey(),
            'direction' => FinancialTransactionDirection::Inbound->value,
            'type' => FinancialTransactionType::CustomerPayment->value,
            'amount' => '100.00',
        ]);
    }

    public function test_payment_fee_creates_separate_outbound_transaction(): void
    {
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '100.00');
        $account = $this->createFinancialAccount($this->company);

        $payment = app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '3.50', PaymentMethod::CreditCard, now(), $account->getKey()),
            $this->user,
        );

        $this->assertDatabaseHas('financial_transactions', [
            'reference_key' => $payment->feeLedgerReferenceKey(),
            'direction' => FinancialTransactionDirection::Outbound->value,
            'type' => FinancialTransactionType::PaymentFee->value,
            'amount' => '3.50',
        ]);
    }

    public function test_net_balance_is_correct_after_payment_and_fee(): void
    {
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '100.00');
        $account = $this->createFinancialAccount($this->company);

        app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '4.00', PaymentMethod::Pix, now(), $account->getKey()),
            $this->user,
        );

        $account->refresh()->load('balance');

        $this->assertSame('96.00', $account->getCurrentBalance());
    }

    public function test_cancelled_payment_reverses_inbound_and_fee_transactions(): void
    {
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '100.00');
        $account = $this->createFinancialAccount($this->company);

        $payment = app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '2.00', PaymentMethod::Pix, now(), $account->getKey()),
            $this->user,
        );

        app(ReceivableService::class)->cancelPayment($this->company, $payment, $this->user, 'Erro');

        $inbound = FinancialTransaction::query()
            ->where('reference_key', $payment->inboundLedgerReferenceKey())
            ->firstOrFail();
        $fee = FinancialTransaction::query()
            ->where('reference_key', $payment->feeLedgerReferenceKey())
            ->firstOrFail();

        $this->assertTrue($inbound->refresh()->isReversed());
        $this->assertTrue($fee->refresh()->isReversed());
        $this->assertSame('0.00', $account->refresh()->getCurrentBalance());
    }

    public function test_cancelled_payment_recalculates_receivable(): void
    {
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '100.00');
        $account = $this->createFinancialAccount($this->company);

        $payment = app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '0.00', PaymentMethod::Pix, now(), $account->getKey()),
            $this->user,
        );

        app(ReceivableService::class)->cancelPayment($this->company, $payment, $this->user, 'Erro');

        $receivable->refresh();

        $this->assertSame(PaymentStatus::Cancelled, $payment->refresh()->status);
        $this->assertSame('0.00', (string) $receivable->paid_amount);
        $this->assertSame('100.00', (string) $receivable->outstanding_amount);
        $this->assertSame(ReceivableStatus::Open, $receivable->status);
    }

    public function test_payment_recalculates_attendance_fees_and_operational_result(): void
    {
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '100.00');
        $account = $this->createFinancialAccount($this->company);

        app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '5.00', PaymentMethod::CreditCard, now(), $account->getKey()),
            $this->user,
        );

        $attendance->refresh();

        $this->assertSame('5.00', (string) $attendance->payment_fee_amount);
        $this->assertSame('70.00', (string) $attendance->operational_result);
    }

    public function test_rejects_financial_account_from_another_company(): void
    {
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '100.00');
        $otherAccount = $this->createFinancialAccount($this->createCompany());

        $this->expectException(ValidationException::class);

        app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '0.00', PaymentMethod::Pix, now(), $otherAccount->getKey()),
            $this->user,
        );
    }

    public function test_cash_payment_requires_open_cash_session(): void
    {
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '100.00');
        $account = $this->createCashAccount($this->company);
        $this->createCashRegister($this->company, $account);

        $this->expectException(ValidationException::class);

        app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '0.00', PaymentMethod::Cash, now(), $account->getKey()),
            $this->user,
        );
    }

    public function test_pix_payment_does_not_require_open_cash_session(): void
    {
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '100.00');
        $account = $this->createCashAccount($this->company);
        $this->createCashRegister($this->company, $account);

        $payment = app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '0.00', PaymentMethod::Pix, now(), $account->getKey()),
            $this->user,
        );

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
    }

    public function test_payment_does_not_create_duplicate_ledger_transactions(): void
    {
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '100.00');
        $account = $this->createFinancialAccount($this->company);

        $payment = app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '0.00', PaymentMethod::Pix, now(), $account->getKey()),
            $this->user,
        );

        $this->assertSame(
            1,
            FinancialTransaction::query()
                ->where('reference_key', $payment->inboundLedgerReferenceKey())
                ->count(),
        );
    }
}
