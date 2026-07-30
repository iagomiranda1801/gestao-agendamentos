<?php

namespace Tests\Feature\Sales;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\FinancialTransactionDirection;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\ReceivableStatus;
use App\Enums\SaleItemType;
use App\Enums\SaleStatus;
use App\Enums\StockDocumentType;
use App\Models\FinancialTransaction;
use App\Models\Service;
use App\Models\ServiceProductConsumption;
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
            'type' => ProductType::Sale,
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
                'unit_price' => '0.00',
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
            'type' => ProductType::Sale,
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

    public function test_consumable_product_cannot_be_sold_as_pos_product(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $account = $this->createFinancialAccount($company);
        $product = $this->createTrackedProduct($company, [
            'name' => 'Forro de maca',
            'type' => ProductType::Consumable,
            'sale_price' => '25.00',
        ]);

        $this->expectException(ValidationException::class);

        app(SaleService::class)->complete($company, $user, [
            'items' => [[
                'product_id' => $product->getKey(),
                'quantity' => '1.0000',
            ]],
            'payments' => [
                new PaymentData('25.00', '0.00', PaymentMethod::Pix, now(), $account->getKey()),
            ],
        ]);
    }

    public function test_completes_custom_sale_without_stock_document(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $account = $this->createFinancialAccount($company);

        $sale = app(SaleService::class)->complete($company, $user, [
            'items' => [[
                'item_type' => SaleItemType::Custom->value,
                'name' => 'Venda avulsa balcão',
                'quantity' => '1.0000',
                'unit_price' => '80.00',
            ]],
            'payments' => [
                new PaymentData('80.00', '0.00', PaymentMethod::Pix, now(), $account->getKey()),
            ],
        ]);

        $this->assertSame(SaleStatus::Paid, $sale->status);

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->getKey(),
            'item_type' => SaleItemType::Custom->value,
            'name_snapshot' => 'Venda avulsa balcão',
            'line_total' => '80.00',
        ]);

        $this->assertDatabaseMissing('stock_documents', [
            'sale_id' => $sale->getKey(),
        ]);
    }

    public function test_completes_service_sale_and_consumes_configured_products(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $account = $this->createFinancialAccount($company);
        $product = $this->createTrackedProduct($company, [
            'name' => 'Creme de tratamento',
            'reference_unit_cost' => '12.000000',
        ]);
        $service = Service::factory()->forCompany($company)->sellable()->create([
            'name' => 'Hidratação expressa',
            'price' => '120.00',
        ]);

        ServiceProductConsumption::query()->forceCreate([
            'company_id' => $company->getKey(),
            'service_id' => $service->getKey(),
            'product_id' => $product->getKey(),
            'quantity' => '0.5000',
        ]);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '2.0000',
            'unit_cost' => '12.000000',
        ]]);

        $sale = app(SaleService::class)->complete($company, $user, [
            'items' => [[
                'item_type' => SaleItemType::Service->value,
                'service_id' => $service->getKey(),
                'quantity' => '2.0000',
            ]],
            'payments' => [
                new PaymentData('240.00', '0.00', PaymentMethod::Pix, now(), $account->getKey()),
            ],
        ]);

        $this->assertSame(SaleStatus::Paid, $sale->status);
        $this->assertSame('1.0000', $product->fresh()->getCurrentStockQuantity());

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->getKey(),
            'item_type' => SaleItemType::Service->value,
            'service_id' => $service->getKey(),
            'name_snapshot' => 'Hidratação expressa',
            'line_total' => '240.00',
        ]);

        $this->assertDatabaseHas('stock_documents', [
            'sale_id' => $sale->getKey(),
            'type' => StockDocumentType::ProductSale->value,
        ]);
    }
}
