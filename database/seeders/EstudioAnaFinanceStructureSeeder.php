<?php

namespace Database\Seeders;

use App\Enums\ExpenseCategoryType;
use App\Enums\FinancialAccountType;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Services\Financial\ExpenseCategoryService;
use App\Services\Financial\FinancialAccountService;
use Illuminate\Database\Seeder;

class EstudioAnaFinanceStructureSeeder extends Seeder
{
    /**
     * @var list<array{name: string, type: ExpenseCategoryType, code?: string|null, affects_managerial_result?: bool, is_system?: bool}>
     */
    protected array $categories = [
        ['name' => 'Aluguel', 'type' => ExpenseCategoryType::Administrative],
        ['name' => 'Água', 'type' => ExpenseCategoryType::Operational],
        ['name' => 'Energia elétrica', 'type' => ExpenseCategoryType::Operational],
        ['name' => 'Internet', 'type' => ExpenseCategoryType::Administrative],
        ['name' => 'Telefone', 'type' => ExpenseCategoryType::Administrative],
        ['name' => 'Marketing', 'type' => ExpenseCategoryType::Marketing],
        ['name' => 'Manutenção', 'type' => ExpenseCategoryType::Operational],
        ['name' => 'Materiais de escritório', 'type' => ExpenseCategoryType::Operational],
        ['name' => 'Limpeza', 'type' => ExpenseCategoryType::Operational],
        ['name' => 'Impostos e tributos', 'type' => ExpenseCategoryType::Tax],
        ['name' => 'Taxas e tarifas', 'type' => ExpenseCategoryType::Financial],
        [
            'name' => 'Taxas de pagamentos',
            'type' => ExpenseCategoryType::Financial,
            'code' => 'payment_fees',
            'affects_managerial_result' => true,
            'is_system' => true,
        ],
        [
            'name' => 'Compra de estoque',
            'type' => ExpenseCategoryType::StockPurchase,
            'affects_managerial_result' => false,
            'is_system' => true,
        ],
        ['name' => 'Outras despesas', 'type' => ExpenseCategoryType::Other],
    ];

    public function run(): void
    {
        $company = Company::query()->where('slug', 'estudio-ana')->first();

        if (! $company) {
            return;
        }

        $cashAccount = $this->ensureAccount($company, [
            'name' => 'Caixa principal',
            'type' => FinancialAccountType::Cash,
            'allow_negative_balance' => false,
            'is_active' => true,
        ]);

        $this->ensureAccount($company, [
            'name' => 'Banco / PIX',
            'type' => FinancialAccountType::Bank,
            'allow_negative_balance' => false,
            'is_active' => true,
            'is_default_receipt_account' => true,
            'is_default_expense_account' => true,
        ]);

        $this->ensureAccount($company, [
            'name' => 'Cartões a receber',
            'type' => FinancialAccountType::CardClearing,
            'allow_negative_balance' => false,
            'is_active' => true,
        ]);

        CashRegister::query()->firstOrCreate(
            [
                'company_id' => $company->getKey(),
                'name' => 'Caixa principal',
            ],
            [
                'financial_account_id' => $cashAccount->getKey(),
                'is_active' => true,
            ],
        );

        $sortOrder = 1;

        foreach ($this->categories as $categoryData) {
            $this->ensureCategory($company, $categoryData, $sortOrder);
            $sortOrder++;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function ensureAccount(Company $company, array $data): FinancialAccount
    {
        $existing = FinancialAccount::query()
            ->where('company_id', $company->getKey())
            ->where('name', $data['name'])
            ->first();

        if ($existing !== null) {
            app(FinancialAccountService::class)->update($company, $existing, $data);

            return $existing->refresh();
        }

        return app(FinancialAccountService::class)->create($company, $data);
    }

    /**
     * @param  array{name: string, type: ExpenseCategoryType, code?: string|null, affects_managerial_result?: bool, is_system?: bool}  $data
     */
    protected function ensureCategory(Company $company, array $data, int $sortOrder): ExpenseCategory
    {
        $existing = ExpenseCategory::query()
            ->where('company_id', $company->getKey())
            ->where('name', $data['name'])
            ->first();

        $payload = [
            'name' => $data['name'],
            'type' => $data['type']->value,
            'code' => $data['code'] ?? null,
            'affects_managerial_result' => $data['affects_managerial_result'] ?? true,
            'is_system' => $data['is_system'] ?? false,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ];

        if ($existing !== null) {
            app(ExpenseCategoryService::class)->update($company, $existing, $payload);
            $existing->forceFill([
                'is_system' => $payload['is_system'],
                'affects_managerial_result' => $payload['affects_managerial_result'],
            ])->save();

            return $existing->refresh();
        }

        $category = app(ExpenseCategoryService::class)->create($company, $payload);
        $category->forceFill([
            'is_system' => $payload['is_system'],
            'affects_managerial_result' => $payload['affects_managerial_result'],
        ])->save();

        return $category->refresh();
    }
}
