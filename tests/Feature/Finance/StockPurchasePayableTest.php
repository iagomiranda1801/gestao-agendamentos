<?php

namespace Tests\Feature\Finance;

use App\Enums\CompanyRole;
use App\Enums\ExpenseCategoryType;
use App\Enums\PayableOrigin;
use App\Models\Payable;
use App\Models\Supplier;
use App\Services\Financial\PayableService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class StockPurchasePayableTest extends TestCase
{
    use CreatesFinanceFixtures;
    use CreatesStockFixtures;

    public function test_posted_purchase_can_generate_payable_from_document_total(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);
        $supplier = Supplier::factory()->forCompany($company)->create(['name' => 'Fornecedor ABC']);
        $category = $this->createStockPurchaseCategory($company);
        $product = $this->createTrackedProduct($company);

        $purchase = $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '5',
            'unit_cost' => '20.000000',
        ]], ['supplier_id' => $supplier->getKey()]);

        $payable = app(PayableService::class)->createFromStockPurchase(
            $company,
            $purchase,
            $category,
            $user,
            [
                'first_due_date' => now()->addDays(30),
                'installment_count' => 1,
            ],
        );

        $this->assertSame('100.00', (string) $payable->total_amount);
        $this->assertSame($supplier->getKey(), $payable->supplier_id);
        $this->assertSame(PayableOrigin::StockPurchase, $payable->origin);
        $this->assertFalse($category->affects_managerial_result);
        $this->assertSame(ExpenseCategoryType::StockPurchase, $category->type);
    }

    public function test_duplicate_generation_is_blocked(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);
        $category = $this->createStockPurchaseCategory($company);
        $product = $this->createTrackedProduct($company);
        $purchase = $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '2',
            'unit_cost' => '50.000000',
        ]]);

        app(PayableService::class)->createFromStockPurchase($company, $purchase, $category, $user);

        $this->expectException(ValidationException::class);

        app(PayableService::class)->createFromStockPurchase($company, $purchase->refresh(), $category, $user);
    }

    public function test_payable_is_linked_to_stock_document(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);
        $category = $this->createStockPurchaseCategory($company);
        $product = $this->createTrackedProduct($company);
        $purchase = $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '1',
            'unit_cost' => '100.000000',
        ]]);

        $payable = app(PayableService::class)->createFromStockPurchase($company, $purchase, $category, $user);

        $purchase->refresh()->load('payable');

        $this->assertNotNull($purchase->payable);
        $this->assertSame($payable->getKey(), $purchase->payable->getKey());
        $this->assertSame(1, Payable::query()->where('stock_document_id', $purchase->getKey())->count());
    }
}
