<?php

namespace Tests\Support;

use App\Enums\ExpenseCategoryType;
use App\Enums\FinancialAccountType;
use App\Enums\FinancialTransactionType;
use App\Models\Attendance;
use App\Models\CashRegister;
use App\Models\CashSession;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\User;
use App\Services\Financial\CashSessionService;
use App\Services\Financial\FinancialLedgerService;
use App\Services\Financial\ReceivableService;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesSchedulingFixtures;

trait CreatesFinanceFixtures
{
    use CreatesSchedulingFixtures;

    protected function createExpenseCategory(Company $company, array $attributes = []): ExpenseCategory
    {
        return ExpenseCategory::factory()->forCompany($company)->create($attributes);
    }

    protected function createStockPurchaseCategory(Company $company): ExpenseCategory
    {
        return ExpenseCategory::factory()->forCompany($company)->stockPurchase()->create([
            'name' => 'Compra de estoque',
        ]);
    }

    protected function createFinancialAccount(Company $company, array $attributes = []): FinancialAccount
    {
        return FinancialAccount::factory()->forCompany($company)->create($attributes);
    }

    protected function fundAccount(
        Company $company,
        FinancialAccount $account,
        string $amount,
        ?User $user = null,
    ): void {
        app(FinancialLedgerService::class)->postInbound(
            $company,
            $account,
            $amount,
            FinancialTransactionType::OpeningBalance,
            CarbonImmutable::now(),
            'Saldo inicial de teste',
            "test-opening-balance:{$account->getKey()}",
            null,
            $user,
        );
    }

    protected function createOperationalCategory(Company $company): ExpenseCategory
    {
        return $this->createExpenseCategory($company, [
            'name' => 'Despesa operacional',
            'type' => ExpenseCategoryType::Operational,
            'affects_managerial_result' => true,
        ]);
    }

    protected function createCashAccount(Company $company, array $attributes = []): FinancialAccount
    {
        return $this->createFinancialAccount($company, array_merge([
            'name' => 'Caixa principal',
            'type' => FinancialAccountType::Cash,
        ], $attributes));
    }

    protected function createCashRegister(Company $company, ?FinancialAccount $account = null, array $attributes = []): CashRegister
    {
        $account ??= $this->createCashAccount($company);

        $register = new CashRegister(array_merge([
            'name' => 'Caixa principal',
            'is_active' => true,
        ], $attributes));
        $register->company()->associate($company);
        $register->financialAccount()->associate($account);
        $register->save();

        return $register->refresh();
    }

    protected function openCashSession(
        Company $company,
        CashRegister $cashRegister,
        User $user,
        string $countedAmount = '0.00',
    ): CashSession {
        return app(CashSessionService::class)->open(
            $company,
            $cashRegister,
            $user,
            $countedAmount,
        );
    }

    protected function createAttendanceReceivable(Company $company, string $finalAmount): array
    {
        $setup = $this->createBookableSetup($company);

        $attendance = Attendance::factory()
            ->forCompany($company)
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'gross_amount' => $finalAmount,
                'discount_amount' => '0.00',
                'final_amount' => $finalAmount,
                'actual_material_cost' => '10.00',
                'commission_amount' => '15.00',
                'client_name_snapshot' => $setup['client']->name,
                'professional_name_snapshot' => $setup['professional']->name,
            ]);

        $receivable = app(ReceivableService::class)->createForAttendance($company, $attendance);

        return [$attendance->refresh(), $receivable];
    }
}
