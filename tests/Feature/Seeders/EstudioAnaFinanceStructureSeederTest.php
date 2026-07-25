<?php

namespace Tests\Feature\Seeders;

use App\Enums\ExpenseCategoryType;
use App\Enums\FinancialAccountType;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\FinancialTransaction;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EstudioAnaFinanceStructureSeeder;
use Database\Seeders\MeasurementUnitSeeder;
use Database\Seeders\TenantFoundationSeeder;
use Tests\TestCase;

class EstudioAnaFinanceStructureSeederTest extends TestCase
{
    protected function seedPrerequisites(): Company
    {
        $this->seed(TenantFoundationSeeder::class);
        $this->seed(MeasurementUnitSeeder::class);
        $this->seed(DemoDataSeeder::class);

        return Company::query()->where('slug', 'estudio-ana')->firstOrFail();
    }

    public function test_seeder_creates_three_financial_accounts(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinanceStructureSeeder::class);

        $this->assertSame(3, FinancialAccount::query()->where('company_id', $company->getKey())->count());
    }

    public function test_seeder_creates_cash_account_named_caixa_principal(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinanceStructureSeeder::class);

        $account = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Caixa principal')
            ->firstOrFail();

        $this->assertSame(FinancialAccountType::Cash, $account->type);
        $this->assertFalse($account->allow_negative_balance);
        $this->assertTrue($account->is_active);
    }

    public function test_seeder_creates_bank_account_as_default_receipt_and_expense(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinanceStructureSeeder::class);

        $account = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Banco / PIX')
            ->firstOrFail();

        $this->assertSame(FinancialAccountType::Bank, $account->type);
        $this->assertTrue($account->is_default_receipt_account);
        $this->assertTrue($account->is_default_expense_account);
    }

    public function test_seeder_creates_card_clearing_account(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinanceStructureSeeder::class);

        $account = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Cartões a receber')
            ->firstOrFail();

        $this->assertSame(FinancialAccountType::CardClearing, $account->type);
    }

    public function test_seeder_creates_active_cash_register_linked_to_cash_account(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinanceStructureSeeder::class);

        $cashAccount = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Caixa principal')
            ->firstOrFail();

        $register = CashRegister::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Caixa principal')
            ->firstOrFail();

        $this->assertTrue($register->is_active);
        $this->assertSame((int) $cashAccount->getKey(), (int) $register->financial_account_id);
    }

    public function test_seeder_creates_fourteen_expense_categories(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinanceStructureSeeder::class);

        $this->assertSame(14, ExpenseCategory::query()->where('company_id', $company->getKey())->count());
    }

    public function test_seeder_configures_stock_purchase_system_category(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinanceStructureSeeder::class);

        $category = ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Compra de estoque')
            ->firstOrFail();

        $this->assertSame(ExpenseCategoryType::StockPurchase, $category->type);
        $this->assertFalse($category->affects_managerial_result);
        $this->assertTrue($category->is_system);
    }

    public function test_seeder_configures_payment_fees_system_category(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinanceStructureSeeder::class);

        $category = ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->where('code', 'payment_fees')
            ->firstOrFail();

        $this->assertSame('Taxas de pagamentos', $category->name);
        $this->assertSame(ExpenseCategoryType::Financial, $category->type);
        $this->assertTrue($category->affects_managerial_result);
        $this->assertTrue($category->is_system);
    }

    public function test_seeder_is_idempotent(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinanceStructureSeeder::class);
        $this->seed(EstudioAnaFinanceStructureSeeder::class);

        $this->assertSame(3, FinancialAccount::query()->where('company_id', $company->getKey())->count());
        $this->assertSame(14, ExpenseCategory::query()->where('company_id', $company->getKey())->count());
        $this->assertSame(1, CashRegister::query()->where('company_id', $company->getKey())->where('name', 'Caixa principal')->count());
    }

    public function test_seeder_does_not_create_opening_balances_or_transactions(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaFinanceStructureSeeder::class);

        $this->assertSame(0, FinancialTransaction::query()->where('company_id', $company->getKey())->count());

        FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->with('balance')
            ->get()
            ->each(function (FinancialAccount $account): void {
                $this->assertSame('0.00', $account->getCurrentBalance());
            });
    }
}
