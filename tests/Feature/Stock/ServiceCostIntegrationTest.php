<?php

namespace Tests\Feature\Stock;

use App\Models\Service;
use App\Services\Service\ServiceCompositionService;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class ServiceCostIntegrationTest extends TestCase
{
    use CreatesStockFixtures;

    public function test_product_without_balance_uses_reference_unit_cost(): void
    {
        $company = $this->createCompany();
        $product = $this->createTrackedProduct($company, ['reference_unit_cost' => '1.500000']);

        $this->assertSame('1.500000', $product->getCurrentUnitCost());
    }

    public function test_product_with_balance_uses_average_unit_cost(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company, ['reference_unit_cost' => '1.500000']);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '3.000000',
        ]]);

        $product->refresh();

        $this->assertSame('3.000000', $product->getCurrentUnitCost());
    }

    public function test_purchase_changes_service_estimated_cost(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company, ['reference_unit_cost' => '2.000000']);
        $service = Service::factory()->forCompany($company)->create(['price' => '100.00']);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '2',
        ]);

        $service->load('consumptions.product.inventoryBalance');
        $before = $service->getEstimatedMaterialCost();

        $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '5.000000',
        ]]);

        $service->refresh()->load('consumptions.product.inventoryBalance');
        $after = $service->getEstimatedMaterialCost();

        $this->assertSame('4.000000', $before);
        $this->assertSame('10.000000', $after);
    }
}
