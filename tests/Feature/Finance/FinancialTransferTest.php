<?php

namespace Tests\Feature\Finance;

use App\DataTransferObjects\Financial\FinancialTransferData;
use App\Enums\CompanyRole;
use App\Enums\FinancialTransactionType;
use App\Models\Company;
use App\Models\FinancialTransaction;
use App\Models\User;
use App\Services\Financial\FinancialTransferService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class FinancialTransferTest extends TestCase
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

    public function test_transfer_reduces_origin_account_balance(): void
    {
        $from = $this->createFinancialAccount($this->company, ['name' => 'Origem']);
        $to = $this->createFinancialAccount($this->company, ['name' => 'Destino']);
        $this->fundAccount($this->company, $from, '200.00', $this->user);

        app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $from->getKey(),
                toFinancialAccountId: $to->getKey(),
                amount: '50.00',
                occurredAt: now(),
                description: 'Transferência teste',
            ),
        );

        $this->assertSame('150.00', $from->refresh()->getCurrentBalance());
    }

    public function test_transfer_increases_destination_account_balance(): void
    {
        $from = $this->createFinancialAccount($this->company, ['name' => 'Origem']);
        $to = $this->createFinancialAccount($this->company, ['name' => 'Destino']);
        $this->fundAccount($this->company, $from, '200.00', $this->user);

        app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $from->getKey(),
                toFinancialAccountId: $to->getKey(),
                amount: '50.00',
                occurredAt: now(),
                description: 'Transferência teste',
            ),
        );

        $this->assertSame('50.00', $to->refresh()->getCurrentBalance());
    }

    public function test_transfer_uses_transfer_transaction_types(): void
    {
        $from = $this->createFinancialAccount($this->company, ['name' => 'Origem']);
        $to = $this->createFinancialAccount($this->company, ['name' => 'Destino']);
        $this->fundAccount($this->company, $from, '100.00', $this->user);

        $transfer = app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $from->getKey(),
                toFinancialAccountId: $to->getKey(),
                amount: '30.00',
                occurredAt: now(),
                description: 'Transferência teste',
            ),
        );

        $this->assertDatabaseHas('financial_transactions', [
            'reference_key' => $transfer->outboundReferenceKey(),
            'type' => FinancialTransactionType::TransferOut->value,
        ]);
        $this->assertDatabaseHas('financial_transactions', [
            'reference_key' => $transfer->inboundReferenceKey(),
            'type' => FinancialTransactionType::TransferIn->value,
        ]);
    }

    public function test_consolidated_balance_is_unchanged_after_transfer(): void
    {
        $from = $this->createFinancialAccount($this->company, ['name' => 'Origem']);
        $to = $this->createFinancialAccount($this->company, ['name' => 'Destino']);
        $this->fundAccount($this->company, $from, '200.00', $this->user);

        app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $from->getKey(),
                toFinancialAccountId: $to->getKey(),
                amount: '75.00',
                occurredAt: now(),
                description: 'Transferência teste',
            ),
        );

        $total = bcadd($from->refresh()->getCurrentBalance(), $to->refresh()->getCurrentBalance(), 2);

        $this->assertSame('200.00', $total);
    }

    public function test_rejects_transfer_between_same_account(): void
    {
        $account = $this->createFinancialAccount($this->company);
        $this->fundAccount($this->company, $account, '100.00', $this->user);

        $this->expectException(ValidationException::class);

        app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $account->getKey(),
                toFinancialAccountId: $account->getKey(),
                amount: '10.00',
                occurredAt: now(),
                description: 'Transferência inválida',
            ),
        );
    }

    public function test_rejects_transfer_with_insufficient_balance(): void
    {
        $from = $this->createFinancialAccount($this->company, ['name' => 'Origem']);
        $to = $this->createFinancialAccount($this->company, ['name' => 'Destino']);
        $this->fundAccount($this->company, $from, '20.00', $this->user);

        $this->expectException(ValidationException::class);

        app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $from->getKey(),
                toFinancialAccountId: $to->getKey(),
                amount: '50.00',
                occurredAt: now(),
                description: 'Transferência inválida',
            ),
        );
    }

    public function test_transfer_fee_reduces_origin_account(): void
    {
        $from = $this->createFinancialAccount($this->company, ['name' => 'Origem']);
        $to = $this->createFinancialAccount($this->company, ['name' => 'Destino']);
        $this->fundAccount($this->company, $from, '200.00', $this->user);

        app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $from->getKey(),
                toFinancialAccountId: $to->getKey(),
                amount: '100.00',
                occurredAt: now(),
                description: 'Transferência com taxa',
                feeAmount: '2.50',
            ),
        );

        $this->assertSame('97.50', $from->refresh()->getCurrentBalance());
        $this->assertSame('100.00', $to->refresh()->getCurrentBalance());
    }

    public function test_reversed_transfer_creates_inverse_movements(): void
    {
        $from = $this->createFinancialAccount($this->company, ['name' => 'Origem']);
        $to = $this->createFinancialAccount($this->company, ['name' => 'Destino']);
        $this->fundAccount($this->company, $from, '200.00', $this->user);

        $transfer = app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $from->getKey(),
                toFinancialAccountId: $to->getKey(),
                amount: '40.00',
                occurredAt: now(),
                description: 'Transferência teste',
            ),
        );

        app(FinancialTransferService::class)->reverse(
            $this->company,
            $transfer,
            $this->user,
            'Estorno de teste',
        );

        $this->assertSame('200.00', $from->refresh()->getCurrentBalance());
        $this->assertSame('0.00', $to->refresh()->getCurrentBalance());

        $outbound = FinancialTransaction::query()
            ->where('reference_key', $transfer->outboundReferenceKey())
            ->firstOrFail();

        $this->assertTrue($outbound->refresh()->isReversed());
    }

    public function test_double_reversal_is_prevented(): void
    {
        $from = $this->createFinancialAccount($this->company, ['name' => 'Origem']);
        $to = $this->createFinancialAccount($this->company, ['name' => 'Destino']);
        $this->fundAccount($this->company, $from, '100.00', $this->user);

        $transfer = app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $from->getKey(),
                toFinancialAccountId: $to->getKey(),
                amount: '20.00',
                occurredAt: now(),
                description: 'Transferência teste',
            ),
        );

        $service = app(FinancialTransferService::class);
        $service->reverse($this->company, $transfer, $this->user, 'Primeiro estorno');

        $this->expectException(ValidationException::class);

        $service->reverse($this->company, $transfer->refresh(), $this->user, 'Segundo estorno');
    }

    public function test_transfer_locks_accounts_in_id_order(): void
    {
        $first = $this->createFinancialAccount($this->company, ['name' => 'Conta A']);
        $second = $this->createFinancialAccount($this->company, ['name' => 'Conta B']);

        if ($first->getKey() > $second->getKey()) {
            [$from, $to] = [$second, $first];
        } else {
            [$from, $to] = [$first, $second];
        }

        $this->fundAccount($this->company, $from, '100.00', $this->user);

        $transfer = app(FinancialTransferService::class)->transfer(
            $this->company,
            $this->user,
            new FinancialTransferData(
                fromFinancialAccountId: $from->getKey(),
                toFinancialAccountId: $to->getKey(),
                amount: '25.00',
                occurredAt: now(),
                description: 'Transferência concorrente',
            ),
        );

        $this->assertNotNull($transfer->getKey());
        $this->assertSame('75.00', $from->refresh()->getCurrentBalance());
        $this->assertSame('25.00', $to->refresh()->getCurrentBalance());
    }
}
