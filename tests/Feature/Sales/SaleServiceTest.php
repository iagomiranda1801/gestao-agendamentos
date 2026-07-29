<?php

namespace Tests\Feature\Sales;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\FinancialTransactionDirection;
use App\Enums\PaymentMethod;
use App\Enums\ReceivableStatus;
use App\Enums\SaleStatus;
use App\Enums\StockDocumentType;
use App\Models\FinancialTransaction;
use App\Services\Sales\SaleService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesFinanceFixtures;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class SaleServiceTest extends TestCase
{
    use CreatesFinanceFixtures;
    use CreatesStockFixtures;

    public function test_completes_paid_product_sale_with_stock_and_financial_records(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $account = $this->createFinancialAccount($company);
        $product = $this->createTrackedProduct($company, [
            'name' => 'Pomada modeladora',
            'sale_price' => '25.00',
            'reference_unit_cost' => '10.000000',
        ]);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '5.0000',
            'unit_cost' => '10.000000',
        ]]);

        $sale = app(SaleService::class)->complete($company, $user, [
            'items' => [[
                'product_id' => $product->getKey(),
                'quantity' => '2.0000',
            ]],
            'payments' => [
                new PaymentData('50.00', '0.00', PaymentMethod::Pix, now(), $account->getKey()),
            ],
        ]);

        $this->assertSame(SaleStatus::Paid, $sale->status);
        $this->assertSame('50.00', $sale->final_amount);
        $this->assertSame('50.00', $sale->paid_amount);
        $this->assertSame('0.00', $sale->outstanding_amount);
        $this->assertSame('3.0000', $product->fresh()->getCurrentStockQuantity());

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->getKey(),
            'product_id' => $product->getKey(),
            'name_snapshot' => 'Pomada modeladora',
        ]);

        $this->assertDatabaseHas('stock_documents', [
            'sale_id' => $sale->getKey(),
            'type' => StockDocumentType::ProductSale->value,
        ]);

        $this->assertDatabaseHas('receivables', [
            'sale_id' => $sale->getKey(),
            'status' => ReceivableStatus::Paid->value,
            'original_amount' => '50.00',
        ]);

        $this->assertDatabaseHas('payments', [
            'sale_id' => $sale->getKey(),
            'amount' => '50.00',
            'method' => PaymentMethod::Pix->value,
        ]);

        $this->assertSame('50.00', $account->fresh()->getCurrentBalance());
        $this->assertTrue(FinancialTransaction::query()
            ->where('company_id', $company->getKey())
            ->where('direction', FinancialTransactionDirection::Inbound)
            ->where('amount', '50.00')
            ->exists());
    }

    public function test_product_sale_fails_when_stock_is_insufficient(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $account = $this->createFinancialAccount($company);
        $product = $this->createTrackedProduct($company, [
            'sale_price' => '25.00',
        ]);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '1.0000',
            'unit_cost' => '10.000000',
        ]]);

        $this->expectException(ValidationException::class);

        app(SaleService::class)->complete($company, $user, [
            'items' => [[
                'product_id' => $product->getKey(),
                'quantity' => '2.0000',
            ]],
            'payments' => [
                new PaymentData('50.00', '0.00', PaymentMethod::Pix, now(), $account->getKey()),
            ],
        ]);
    }
}
