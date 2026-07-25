<?php

namespace Tests\Feature\Finance;

use App\Enums\CashSessionStatus;
use App\Enums\FinancialTransactionType;
use App\Filament\App\Resources\FinancialAccounts\FinancialAccountResource;
use App\Filament\App\Resources\FinancialAccounts\Pages\ListFinancialAccounts;
use App\Filament\App\Resources\FinancialAccounts\Schemas\FinancialAccountForm;
use App\Models\FinancialAccountBalance;
use App\Services\Financial\FinancialAccountService;
use App\Services\Financial\FinancialLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FinancialAccountTest extends TestCase
{
    public function test_account_is_created_with_zero_balance(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        $account = app(FinancialAccountService::class)->create($company, [
            'name' => 'Caixa principal',
            'type' => 'cash',
        ]);

        $account->load('balance');

        $this->assertNotNull($account->balance);
        $this->assertSame('0.00', (string) $account->balance->current_balance);
    }

    public function test_balance_cannot_be_edited_in_resource_form(): void
    {
        $source = file_get_contents(
            (new \ReflectionClass(FinancialAccountForm::class))->getFileName(),
        );

        $this->assertStringNotContainsString("make('current_balance')", $source);
        $this->assertStringNotContainsString("make('balance')", $source);
    }

    public function test_account_from_other_company_is_not_listed(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);

        $visible = app(FinancialAccountService::class)->create($company, [
            'name' => 'Conta visível',
            'type' => 'bank',
        ]);

        app(FinancialAccountService::class)->create($otherCompany, [
            'name' => 'Conta oculta',
            'type' => 'bank',
        ]);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(ListFinancialAccounts::class)->assertSuccessful();

        $records = FinancialAccountResource::getEloquentQuery()->get();

        $this->assertTrue($records->contains('id', $visible->id));
        $this->assertCount(1, $records);
    }

    public function test_default_accounts_are_unique_per_company(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        $first = app(FinancialAccountService::class)->create($company, [
            'name' => 'Banco A',
            'type' => 'bank',
            'is_default_receipt_account' => true,
            'is_default_expense_account' => true,
        ]);

        $second = app(FinancialAccountService::class)->create($company, [
            'name' => 'Banco B',
            'type' => 'bank',
            'is_default_receipt_account' => true,
            'is_default_expense_account' => true,
        ]);

        $first->refresh();
        $second->refresh();

        $this->assertFalse($first->is_default_receipt_account);
        $this->assertFalse($first->is_default_expense_account);
        $this->assertTrue($second->is_default_receipt_account);
        $this->assertTrue($second->is_default_expense_account);
    }

    public function test_account_with_open_cash_session_cannot_be_deactivated(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $user = $this->createCompanyUser($company);

        $account = app(FinancialAccountService::class)->create($company, [
            'name' => 'Caixa',
            'type' => 'cash',
        ]);

        $registerId = DB::table('cash_registers')->insertGetId([
            'company_id' => $company->id,
            'financial_account_id' => $account->id,
            'name' => 'Caixa principal',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('cash_sessions')->insert([
            'company_id' => $company->id,
            'cash_register_id' => $registerId,
            'status' => CashSessionStatus::Open->value,
            'opened_by' => $user->id,
            'opened_at' => now(),
            'expected_opening_amount' => '0.00',
            'counted_opening_amount' => '0.00',
            'opening_difference_amount' => '0.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(ValidationException::class);

        app(FinancialAccountService::class)->deactivate($company, $account);
    }

    public function test_account_blocks_outbound_greater_than_balance(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        $account = app(FinancialAccountService::class)->create($company, [
            'name' => 'Conta restrita',
            'type' => 'bank',
            'allow_negative_balance' => false,
        ]);

        $this->expectException(ValidationException::class);

        app(FinancialLedgerService::class)->postOutbound(
            company: $company,
            account: $account,
            amount: '10.00',
            type: FinancialTransactionType::ExpensePayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Pagamento teste',
            referenceKey: 'test:outbound:1',
        );
    }

    public function test_account_with_negative_balance_allowed_permits_outbound(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        $account = app(FinancialAccountService::class)->create($company, [
            'name' => 'Conta flexível',
            'type' => 'bank',
            'allow_negative_balance' => true,
        ]);

        app(FinancialLedgerService::class)->postOutbound(
            company: $company,
            account: $account,
            amount: '10.00',
            type: FinancialTransactionType::ExpensePayment,
            occurredAt: CarbonImmutable::now(),
            description: 'Pagamento teste',
            referenceKey: 'test:outbound:negative',
        );

        $account->refresh()->load('balance');

        $this->assertSame('-10.00', (string) $account->balance->current_balance);
    }

    public function test_manipulated_company_id_is_rejected_on_create(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);

        $account = app(FinancialAccountService::class)->create($company, [
            'name' => 'Conta segura',
            'type' => 'bank',
            'company_id' => $otherCompany->id,
        ]);

        $this->assertSame($company->id, $account->company_id);
        $this->assertSame(
            $company->id,
            FinancialAccountBalance::query()->where('financial_account_id', $account->id)->value('company_id'),
        );
    }
}
