<?php

namespace Tests\Feature\Finance;

use App\Enums\CompanyRole;
use App\Enums\PayableOrigin;
use App\Enums\PayableStatus;
use App\Models\Company;
use App\Models\Payable;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Financial\PayableService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class PayableTest extends TestCase
{
    use CreatesFinanceFixtures;
    use CreatesStockFixtures;

    protected Company $company;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createCompany();
        $this->user = $this->createCompanyUser($this->company, [], CompanyRole::CompanyAdmin);
    }

    public function test_draft_does_not_move_cash(): void
    {
        $category = $this->createOperationalCategory($this->company);
        $service = app(PayableService::class);

        $payable = $service->createDraft($this->company, $category, [
            'description' => 'Aluguel',
            'total_amount' => '500.00',
        ], $this->user);

        $this->assertSame(PayableStatus::Draft, $payable->status);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('payable_payments', 0);
    }

    public function test_installment_sum_must_equal_total(): void
    {
        $category = $this->createOperationalCategory($this->company);
        $service = app(PayableService::class);

        $payable = $service->createDraft($this->company, $category, [
            'description' => 'Conta de luz',
            'total_amount' => '100.00',
        ], $this->user);

        $this->expectException(ValidationException::class);

        $service->createInstallments($this->company, $payable, [
            ['due_date' => now()->addDays(10), 'amount' => '50.00'],
            ['due_date' => now()->addDays(40), 'amount' => '40.00'],
        ]);
    }

    public function test_launched_payable_is_open(): void
    {
        $category = $this->createOperationalCategory($this->company);
        $service = app(PayableService::class);

        $payable = $service->createDraft($this->company, $category, [
            'description' => 'Internet',
            'total_amount' => '120.00',
        ], $this->user);

        $service->createInstallments($this->company, $payable, [
            ['due_date' => now()->addDays(15), 'amount' => '120.00'],
        ]);

        $payable = $service->launch($this->company, $payable->refresh());

        $this->assertSame(PayableStatus::Open, $payable->status);
    }

    public function test_cancelled_payable_keeps_history(): void
    {
        $category = $this->createOperationalCategory($this->company);
        $service = app(PayableService::class);

        $payable = $service->createDraft($this->company, $category, [
            'description' => 'Material',
            'total_amount' => '80.00',
        ], $this->user);

        $service->createInstallments($this->company, $payable, [
            ['due_date' => now()->addDays(7), 'amount' => '80.00'],
        ]);

        $payable = $service->launch($this->company, $payable->refresh());
        $payable = $service->cancel($this->company, $payable, $this->user, 'Duplicidade');

        $this->assertSame(PayableStatus::Cancelled, $payable->status);
        $this->assertDatabaseHas('payables', ['id' => $payable->getKey()]);
    }

    public function test_rejects_supplier_from_another_company(): void
    {
        $otherCompany = $this->createCompany();
        $supplier = Supplier::factory()->forCompany($otherCompany)->create();
        $category = $this->createOperationalCategory($this->company);

        $this->expectException(ValidationException::class);

        app(PayableService::class)->createDraft($this->company, $category, [
            'description' => 'Teste',
            'total_amount' => '50.00',
        ], $this->user, $supplier);
    }

    public function test_rejects_category_from_another_company(): void
    {
        $otherCompany = $this->createCompany();
        $category = $this->createOperationalCategory($otherCompany);

        $this->expectException(ValidationException::class);

        app(PayableService::class)->createDraft($this->company, $category, [
            'description' => 'Teste',
            'total_amount' => '50.00',
        ], $this->user);
    }

    public function test_reference_key_prevents_duplicate_payables(): void
    {
        $category = $this->createOperationalCategory($this->company);
        $service = app(PayableService::class);

        $service->createDraft($this->company, $category, [
            'description' => 'Primeira',
            'total_amount' => '100.00',
            'reference_key' => 'manual:test:1',
        ], $this->user);

        $this->expectException(ValidationException::class);

        $service->createDraft($this->company, $category, [
            'description' => 'Segunda',
            'total_amount' => '100.00',
            'reference_key' => 'manual:test:1',
        ], $this->user);
    }

    public function test_quick_expense_unpaid_creates_open_payable_without_ledger(): void
    {
        $category = $this->createOperationalCategory($this->company);

        $payable = app(PayableService::class)->createQuickExpense(
            $this->company,
            $category,
            [
                'description' => 'Conta de água',
                'total_amount' => '75.00',
                'due_date' => now()->addDays(5),
                'paid_now' => false,
            ],
            $this->user,
        );

        $this->assertSame(PayableStatus::Open, $payable->status);
        $this->assertDatabaseCount('financial_transactions', 0);
        $this->assertDatabaseCount('payable_payments', 0);
    }

    public function test_overdue_is_not_persisted_as_status(): void
    {
        $category = $this->createOperationalCategory($this->company);
        $service = app(PayableService::class);

        $payable = $service->createDraft($this->company, $category, [
            'description' => 'Vencida',
            'total_amount' => '90.00',
        ], $this->user);

        $service->createInstallments($this->company, $payable, [
            ['due_date' => now()->subDays(5), 'amount' => '90.00'],
        ]);

        $payable = $service->launch($this->company, $payable->refresh());

        $this->assertSame(PayableStatus::Open, $payable->status);
        $this->assertTrue($payable->installments->first()->due_date->isPast());
    }

    public function test_payables_are_scoped_by_company(): void
    {
        $otherCompany = $this->createCompany();
        $category = $this->createOperationalCategory($this->company);
        $otherCategory = $this->createOperationalCategory($otherCompany);

        app(PayableService::class)->createDraft($this->company, $category, [
            'description' => 'Empresa A',
            'total_amount' => '100.00',
        ], $this->user);

        app(PayableService::class)->createDraft($otherCompany, $otherCategory, [
            'description' => 'Empresa B',
            'total_amount' => '200.00',
        ], $this->createCompanyUser($otherCompany));

        $this->assertSame(1, Payable::query()->where('company_id', $this->company->getKey())->count());
        $this->assertSame(1, Payable::query()->where('company_id', $otherCompany->getKey())->count());
    }

    public function test_create_from_stock_purchase_uses_document_total(): void
    {
        $category = $this->createStockPurchaseCategory($this->company);
        $purchase = $this->createPostedPurchase('250.00');

        $payable = app(PayableService::class)->createFromStockPurchase(
            $this->company,
            $purchase,
            $category,
            $this->user,
        );

        $this->assertSame(PayableOrigin::StockPurchase, $payable->origin);
        $this->assertSame('250.00', (string) $payable->total_amount);
        $this->assertSame($purchase->getKey(), $payable->stock_document_id);
        $this->assertSame("stock-purchase:{$purchase->getKey()}:payable", $payable->reference_key);
    }

    protected function createPostedPurchase(string $totalAmount = '100.00')
    {
        $product = $this->createTrackedProduct($this->company);
        $quantity = '10';
        $unitCost = bcdiv($totalAmount, $quantity, 6);

        return $this->postPurchase($this->company, $this->user, [[
            'product_id' => $product->getKey(),
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
        ]]);
    }
}
