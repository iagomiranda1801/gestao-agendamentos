<?php

namespace Tests\Feature\Seeders;

use App\Enums\StockDocumentStatus;
use App\Enums\StockDocumentType;
use App\Models\Company;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\StockDocument;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EstudioAnaCatalogSeeder;
use Database\Seeders\EstudioAnaOpeningInventorySeeder;
use Database\Seeders\MeasurementUnitSeeder;
use Database\Seeders\TenantFoundationSeeder;
use Tests\TestCase;

class EstudioAnaOpeningInventorySeederTest extends TestCase
{
    protected function seedCatalogAndOpening(): Company
    {
        $this->seed(TenantFoundationSeeder::class);
        $this->seed(MeasurementUnitSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $this->seed(EstudioAnaCatalogSeeder::class);
        $this->seed(EstudioAnaOpeningInventorySeeder::class);

        return Company::query()->where('slug', 'estudio-ana')->firstOrFail();
    }

    public function test_creates_single_opening_balance_document(): void
    {
        $company = $this->seedCatalogAndOpening();

        $count = StockDocument::query()
            ->where('company_id', $company->getKey())
            ->where('type', StockDocumentType::OpeningBalance)
            ->where('status', StockDocumentStatus::Posted)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_running_twice_does_not_duplicate(): void
    {
        $company = $this->seedCatalogAndOpening();
        $this->seed(EstudioAnaOpeningInventorySeeder::class);

        $movements = InventoryBalance::query()->where('company_id', $company->getKey())->count();

        $this->assertSame(29, $movements);
    }

    public function test_creates_twenty_nine_balances(): void
    {
        $company = $this->seedCatalogAndOpening();

        $this->assertSame(29, InventoryBalance::query()->where('company_id', $company->getKey())->count());
    }

    public function test_algoda_prensado_stock_is_973(): void
    {
        $company = $this->seedCatalogAndOpening();

        $product = Product::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Algodão Prensado')
            ->firstOrFail();

        $balance = InventoryBalance::query()->where('product_id', $product->getKey())->firstOrFail();

        $this->assertSame('973.0000', (string) $balance->quantity_on_hand);
    }

    public function test_aplicador_stock_is_315(): void
    {
        $company = $this->seedCatalogAndOpening();

        $product = Product::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Aplicador')
            ->firstOrFail();

        $balance = InventoryBalance::query()->where('product_id', $product->getKey())->firstOrFail();

        $this->assertSame('315.0000', (string) $balance->quantity_on_hand);
    }

    public function test_papel_toalha_stock_is_85(): void
    {
        $company = $this->seedCatalogAndOpening();

        $product = Product::query()
            ->where('company_id', $company->getKey())
            ->where('name', 'Papel Toalha')
            ->firstOrFail();

        $balance = InventoryBalance::query()->where('product_id', $product->getKey())->firstOrFail();

        $this->assertSame('85.0000', (string) $balance->quantity_on_hand);
    }

    public function test_uses_reference_key(): void
    {
        $company = $this->seedCatalogAndOpening();

        $document = StockDocument::query()
            ->where('company_id', $company->getKey())
            ->where('reference_key', EstudioAnaOpeningInventorySeeder::REFERENCE_KEY)
            ->firstOrFail();

        $this->assertSame(StockDocumentStatus::Posted, $document->status);
        $this->assertSame(29, $document->items()->count());
    }
}
