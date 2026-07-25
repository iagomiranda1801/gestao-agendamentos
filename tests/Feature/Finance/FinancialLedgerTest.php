<?php

namespace Tests\Feature\Finance;

use App\Enums\FinancialTransactionDirection;
use App\Enums\FinancialTransactionType;
use App\Models\FinancialTransaction;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialLedgerTest extends TestCase
{
    protected function createAccountWithBalance(string $initialBalance = '0.00'): array
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $user = $this->createCompanyUser($company);

        $account = app(FinancialAccountService::class)->create($company, [
            'name' => 'Conta teste',
            'type' => 'bank',
        ]);

        if (bccomp($initialBalance, '0', 2) > 0) {
            app(FinancialLedgerService::class)->postInbound(
                company: $company,
                account: $account,
                amount: $initialBalance,
                type: FinancialTransactionType::OpeningBalance,
                occurredAt: CarbonImmutable::now(),
                description: 'Saldo inicial',
                referenceKey: 'opening:'.$account->id,
                user: $user,
            );
            $account->refresh()->load('balance');
        }

        return [$company, $user, $account];
    }

    public function test_inbound_increases_balance(): void
    {
        [$company, $user, $account] = $this->createAccountWithBalance();

        app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $account,
            amount: '100.00',
            type: FinancialTransactionType::CustomerPayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Recebimento',
            referenceKey: 'inbound:1',
            user: $user,
        );

        $account->refresh()->load('balance');

        $this->assertSame('100.00', (string) $account->balance->current_balance);
    }

    public function test_outbound_decreases_balance(): void
    {
        [$company, $user, $account] = $this->createAccountWithBalance('100.00');

        app(FinancialLedgerService::class)->postOutbound(
            company: $company,
            account: $account,
            amount: '30.00',
            type: FinancialTransactionType::ExpensePayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Pagamento',
            referenceKey: 'outbound:1',
            user: $user,
        );

        $account->refresh()->load('balance');

        $this->assertSame('70.00', (string) $account->balance->current_balance);
    }

    public function test_transaction_amount_must_be_positive(): void
    {
        [$company, , $account] = $this->createAccountWithBalance();

        $this->expectException(ValidationException::class);

        app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $account,
            amount: '0.00',
            type: FinancialTransactionType::CustomerPayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Valor inválido',
            referenceKey: 'invalid:zero',
        );
    }

    public function test_direction_determines_balance_effect(): void
    {
        [$company, $user, $account] = $this->createAccountWithBalance('50.00');

        app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $account,
            amount: '20.00',
            type: FinancialTransactionType::TransferIn,
            occurredAt: CarbonImmutable::now(),
            description: 'Entrada',
            referenceKey: 'direction:in',
            user: $user,
        );

        app(FinancialLedgerService::class)->postOutbound(
            company: $company,
            account: $account,
            amount: '15.00',
            type: FinancialTransactionType::TransferOut,
            occurredAt: CarbonImmutable::now(),
            description: 'Saída',
            referenceKey: 'direction:out',
            user: $user,
        );

        $account->refresh()->load('balance');

        $this->assertSame('55.00', (string) $account->balance->current_balance);
    }

    public function test_reference_key_prevents_duplicates(): void
    {
        [$company, $user, $account] = $this->createAccountWithBalance();

        app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $account,
            amount: '10.00',
            type: FinancialTransactionType::CustomerPayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Primeira',
            referenceKey: 'duplicate:key',
            user: $user,
        );

        $this->expectException(ValidationException::class);

        app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $account,
            amount: '10.00',
            type: FinancialTransactionType::CustomerPayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Duplicada',
            referenceKey: 'duplicate:key',
            user: $user,
        );
    }

    public function test_reversal_creates_inverse_transaction(): void
    {
        [$company, $user, $account] = $this->createAccountWithBalance();

        $original = app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $account,
            amount: '40.00',
            type: FinancialTransactionType::CustomerPayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Recebimento',
            referenceKey: 'reversal:original',
            user: $user,
        );

        $reversal = app(FinancialLedgerService::class)->reverse(
            company: $company,
            transaction: $original,
            user: $user,
            reason: 'Correção',
        );

        $this->assertSame(FinancialTransactionDirection::Outbound, $reversal->direction);
        $this->assertSame(FinancialTransactionType::Reversal, $reversal->type);
        $this->assertSame('40.00', (string) $reversal->amount);
        $this->assertSame($original->id, $reversal->original_transaction_id);

        $account->refresh()->load('balance');
        $this->assertSame('0.00', (string) $account->balance->current_balance);
    }

    public function test_original_transaction_remains_after_reversal(): void
    {
        [$company, $user, $account] = $this->createAccountWithBalance();

        $original = app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $account,
            amount: '25.00',
            type: FinancialTransactionType::CustomerPayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Recebimento',
            referenceKey: 'remain:original',
            user: $user,
        );

        app(FinancialLedgerService::class)->reverse(
            company: $company,
            transaction: $original,
            user: $user,
            reason: 'Correção',
        );

        $this->assertNotNull(FinancialTransaction::query()->find($original->id));
        $original->refresh();
        $this->assertNotNull($original->reversed_at);
    }

    public function test_double_reversal_is_prevented(): void
    {
        [$company, $user, $account] = $this->createAccountWithBalance();

        $original = app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $account,
            amount: '25.00',
            type: FinancialTransactionType::CustomerPayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Recebimento',
            referenceKey: 'double:reversal',
            user: $user,
        );

        app(FinancialLedgerService::class)->reverse(
            company: $company,
            transaction: $original,
            user: $user,
            reason: 'Primeiro estorno',
        );

        $this->expectException(ValidationException::class);

        app(FinancialLedgerService::class)->reverse(
            company: $company,
            transaction: $original->fresh(),
            user: $user,
            reason: 'Segundo estorno',
        );
    }

    public function test_failed_posting_does_not_change_balance_or_create_transaction(): void
    {
        [$company, , $account] = $this->createAccountWithBalance('5.00');

        try {
            app(FinancialLedgerService::class)->postOutbound(
                company: $company,
                account: $account,
                amount: '10.00',
                type: FinancialTransactionType::ExpensePayment,
                occurredAt: CarbonImmutable::now(),
                description: 'Falha esperada',
                referenceKey: 'failed:outbound',
            );
        } catch (ValidationException) {
            // expected
        }

        $account->refresh()->load('balance');

        $this->assertSame('5.00', (string) $account->balance->current_balance);
        $this->assertDatabaseMissing('financial_transactions', [
            'reference_key' => 'failed:outbound',
        ]);
    }

    public function test_concurrency_keeps_balance_correct(): void
    {
        [$company, $user, $account] = $this->createAccountWithBalance();

        $ledger = app(FinancialLedgerService::class);

        foreach (range(1, 5) as $index) {
            $ledger->postInbound(
                company: $company,
                account: $account,
                amount: '10.00',
                type: FinancialTransactionType::CustomerPayment,
                occurredAt: CarbonImmutable::now(),
                description: 'Entrada '.$index,
                referenceKey: 'concurrency:'.$index,
                user: $user,
            );
        }

        $account->refresh()->load('balance');

        $this->assertSame('50.00', (string) $account->balance->current_balance);
        $this->assertSame(5, FinancialTransaction::query()->where('financial_account_id', $account->id)->count());
    }

    public function test_transaction_cannot_be_edited(): void
    {
        [$company, $user, $account] = $this->createAccountWithBalance();

        $transaction = app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $account,
            amount: '10.00',
            type: FinancialTransactionType::CustomerPayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Original',
            referenceKey: 'immutable:edit',
            user: $user,
        );

        $this->expectException(\RuntimeException::class);

        $transaction->update(['description' => 'Alterado']);
    }

    public function test_transaction_cannot_be_deleted(): void
    {
        [$company, $user, $account] = $this->createAccountWithBalance();

        $transaction = app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $account,
            amount: '10.00',
            type: FinancialTransactionType::CustomerPayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Original',
            referenceKey: 'immutable:delete',
            user: $user,
        );

        $this->expectException(\RuntimeException::class);

        $transaction->delete();
    }

    public function test_other_company_account_is_rejected(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);

        $foreignAccount = app(FinancialAccountService::class)->create($otherCompany, [
            'name' => 'Conta estrangeira',
            'type' => 'bank',
        ]);

        $this->expectException(ValidationException::class);

        app(FinancialLedgerService::class)->postInbound(
            company: $company,
            account: $foreignAccount,
            amount: '10.00',
            type: FinancialTransactionType::CustomerPayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Empresa errada',
            referenceKey: 'wrong:company',
        );
    }
}
