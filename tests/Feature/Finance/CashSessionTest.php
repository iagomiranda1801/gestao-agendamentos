<?php

namespace Tests\Feature\Finance;

use App\DataTransferObjects\Financial\PayablePaymentData;
use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\CompanyRole;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialTransactionType;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\User;
use App\Services\Financial\CashSessionService;
use App\Services\Financial\PayablePaymentService;
use App\Services\Financial\PayableService;
use App\Services\Financial\ReceivableService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class CashSessionTest extends TestCase
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

    public function test_cash_register_requires_cash_account_type(): void
    {
        $account = $this->createCashAccount($this->company);
        $register = $this->createCashRegister($this->company, $account);

        $register->load('financialAccount');

        $this->assertTrue($register->financialAccount->isCashAccount());
        $this->assertSame(FinancialAccountType::Cash, $register->financialAccount->type);
    }

    public function test_cannot_open_two_sessions_simultaneously(): void
    {
        $register = $this->createCashRegister($this->company, $this->createCashAccount($this->company));
        $service = app(CashSessionService::class);

        $service->open($this->company, $register, $this->user, '0.00');

        $this->expectException(ValidationException::class);

        $service->open($this->company, $register, $this->user, '0.00');
    }

    public function test_opening_session_uses_expected_account_balance(): void
    {
        $account = $this->createCashAccount($this->company);
        $this->fundAccount($this->company, $account, '150.00', $this->user);
        $register = $this->createCashRegister($this->company, $account);

        $session = app(CashSessionService::class)->open(
            $this->company,
            $register,
            $this->user,
            '150.00',
        );

        $this->assertSame('150.00', (string) $session->expected_opening_amount);
    }

    public function test_opening_difference_is_calculated(): void
    {
        $account = $this->createCashAccount($this->company);
        $this->fundAccount($this->company, $account, '100.00', $this->user);
        $register = $this->createCashRegister($this->company, $account);

        $session = app(CashSessionService::class)->open(
            $this->company,
            $register,
            $this->user,
            '95.00',
        );

        $this->assertSame('-5.00', (string) $session->opening_difference_amount);
    }

    public function test_cash_customer_payment_is_linked_to_session(): void
    {
        $account = $this->createCashAccount($this->company);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '0.00');
        [$attendance, $receivable] = $this->createAttendanceReceivable($this->company, '80.00');

        app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('80.00', '0.00', PaymentMethod::Cash, now(), $account->getKey()),
            $this->user,
        );

        $this->assertDatabaseHas('financial_transactions', [
            'cash_session_id' => $session->getKey(),
            'type' => FinancialTransactionType::CustomerPayment->value,
        ]);
    }

    public function test_cash_expense_payment_is_linked_to_session(): void
    {
        $account = $this->createCashAccount($this->company);
        $this->fundAccount($this->company, $account, '200.00', $this->user);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '200.00');

        [$payable, $installment] = $this->createOpenPayable('50.00');

        app(PayablePaymentService::class)->record(
            $this->company,
            $installment,
            $account,
            $this->user,
            new PayablePaymentData(
                settledPrincipalAmount: '50.00',
                method: PaymentMethod::Cash,
                paidAt: now(),
            ),
        );

        $this->assertDatabaseHas('financial_transactions', [
            'cash_session_id' => $session->getKey(),
            'type' => FinancialTransactionType::ExpensePayment->value,
        ]);
    }

    public function test_reinforcement_increases_balance(): void
    {
        $account = $this->createCashAccount($this->company);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '0.00');

        app(CashSessionService::class)->reinforcement(
            $this->company,
            $session,
            $this->user,
            '100.00',
            'Reforço inicial',
        );

        $this->assertSame('100.00', $account->refresh()->getCurrentBalance());
    }

    public function test_withdrawal_reduces_balance(): void
    {
        $account = $this->createCashAccount($this->company);
        $this->fundAccount($this->company, $account, '200.00', $this->user);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '200.00');

        app(CashSessionService::class)->withdrawal(
            $this->company,
            $session,
            $this->user,
            '30.00',
            'Sangria operacional',
        );

        $this->assertSame('170.00', $account->refresh()->getCurrentBalance());
    }

    public function test_withdrawal_is_not_expense_payment_type(): void
    {
        $account = $this->createCashAccount($this->company);
        $this->fundAccount($this->company, $account, '100.00', $this->user);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '100.00');

        app(CashSessionService::class)->withdrawal(
            $this->company,
            $session,
            $this->user,
            '20.00',
            'Sangria',
        );

        $this->assertDatabaseHas('financial_transactions', [
            'type' => FinancialTransactionType::CashWithdrawal->value,
        ]);
        $this->assertDatabaseMissing('financial_transactions', [
            'type' => FinancialTransactionType::ExpensePayment->value,
            'amount' => '20.00',
        ]);
    }

    public function test_reinforcement_is_not_customer_payment_type(): void
    {
        $account = $this->createCashAccount($this->company);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '0.00');

        app(CashSessionService::class)->reinforcement(
            $this->company,
            $session,
            $this->user,
            '50.00',
            'Reforço',
        );

        $this->assertDatabaseHas('financial_transactions', [
            'type' => FinancialTransactionType::CashReinforcement->value,
        ]);
        $this->assertDatabaseMissing('financial_transactions', [
            'type' => FinancialTransactionType::CustomerPayment->value,
            'amount' => '50.00',
        ]);
    }

    public function test_closing_session_calculates_expected_amount(): void
    {
        $account = $this->createCashAccount($this->company);
        $this->fundAccount($this->company, $account, '120.00', $this->user);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '120.00');

        $closed = app(CashSessionService::class)->close(
            $this->company,
            $session,
            $this->user,
            '120.00',
        );

        $this->assertSame('120.00', (string) $closed->expected_closing_amount);
    }

    public function test_closing_difference_is_calculated(): void
    {
        $account = $this->createCashAccount($this->company);
        $this->fundAccount($this->company, $account, '100.00', $this->user);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '100.00');

        $closed = app(CashSessionService::class)->close(
            $this->company,
            $session,
            $this->user,
            '98.00',
        );

        $this->assertSame('-2.00', (string) $closed->closing_difference_amount);
    }

    public function test_closing_does_not_automatically_adjust_balance(): void
    {
        $account = $this->createCashAccount($this->company);
        $this->fundAccount($this->company, $account, '100.00', $this->user);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '100.00');

        app(CashSessionService::class)->close(
            $this->company,
            $session,
            $this->user,
            '90.00',
        );

        $this->assertSame('100.00', $account->refresh()->getCurrentBalance());
    }

    public function test_closed_session_rejects_new_adjustments(): void
    {
        $account = $this->createCashAccount($this->company);
        $this->fundAccount($this->company, $account, '100.00', $this->user);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '100.00');

        $closed = app(CashSessionService::class)->close(
            $this->company,
            $session,
            $this->user,
            '100.00',
        );

        $this->expectException(ValidationException::class);

        app(CashSessionService::class)->reinforcement(
            $this->company,
            $closed,
            $this->user,
            '10.00',
            'Tentativa inválida',
        );
    }

    public function test_double_closing_is_prevented(): void
    {
        $account = $this->createCashAccount($this->company);
        $register = $this->createCashRegister($this->company, $account);
        $session = $this->openCashSession($this->company, $register, $this->user, '0.00');

        $service = app(CashSessionService::class);
        $closed = $service->close($this->company, $session, $this->user, '0.00');

        $this->expectException(ValidationException::class);

        $service->close($this->company, $closed, $this->user, '0.00');
    }

    public function test_cash_register_from_another_company_is_not_accessible(): void
    {
        $otherCompany = $this->createCompany();
        $otherRegister = $this->createCashRegister($otherCompany, $this->createCashAccount($otherCompany));

        $this->expectException(HttpException::class);

        app(CashSessionService::class)->open(
            $this->company,
            $otherRegister,
            $this->user,
            '0.00',
        );
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
