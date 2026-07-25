<?php

namespace Tests\Feature\Services;

use App\Models\MeasurementUnit;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceProductConsumption;
use App\Services\Service\ServiceCompositionService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ServiceCompositionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MeasurementUnit::factory()->create(['code' => 'unit', 'is_active' => true]);
    }

    public function test_consumable_product_from_same_company_can_be_associated(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $product = Product::factory()->forCompany($company)->consumable()->create();

        $consumption = app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '2',
        ]);

        $this->assertSame($product->getKey(), $consumption->product_id);
        $this->assertSame($service->getKey(), $consumption->service_id);
    }

    public function test_asset_product_cannot_be_associated(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $product = Product::factory()->forCompany($company)->asset()->create();

        $this->expectException(ValidationException::class);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '1',
        ]);
    }

    public function test_product_from_other_company_cannot_be_associated(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $product = Product::factory()->forCompany($otherCompany)->consumable()->create();

        $this->expectException(ValidationException::class);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '1',
        ]);
    }

    public function test_inactive_product_cannot_be_associated(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $product = Product::factory()->forCompany($company)->consumable()->inactive()->create();

        $this->expectException(ValidationException::class);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '1',
        ]);
    }

    public function test_service_from_other_company_cannot_receive_composition(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $service = Service::factory()->forCompany($otherCompany)->create();
        $product = Product::factory()->forCompany($company)->consumable()->create();

        $this->expectException(HttpException::class);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '1',
        ]);
    }

    public function test_zero_quantity_is_prevented(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $product = Product::factory()->forCompany($company)->consumable()->create();

        $this->expectException(ValidationException::class);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '0',
        ]);
    }

    public function test_negative_quantity_is_prevented(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $product = Product::factory()->forCompany($company)->consumable()->create();

        $this->expectException(ValidationException::class);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '-1',
        ]);
    }

    public function test_duplicate_product_in_same_service_is_prevented(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $product = Product::factory()->forCompany($company)->consumable()->create();

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '1',
        ]);

        $this->expectException(ValidationException::class);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '2',
        ]);
    }

    public function test_total_cost_is_calculated_dynamically(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create(['price' => '100.00']);
        $product = Product::factory()->forCompany($company)->consumable()->create([
            'reference_unit_cost' => '1.500000',
        ]);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '4',
        ]);

        $service->refresh()->load('consumptions.product');

        $this->assertSame('6.000000', $service->getEstimatedMaterialCost());
        $this->assertSame('94.00', $service->getEstimatedGrossMargin());
    }

    public function test_changing_reference_unit_cost_updates_estimated_service_cost(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $product = Product::factory()->forCompany($company)->consumable()->create([
            'reference_unit_cost' => '1.000000',
        ]);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '2',
        ]);

        $product->update(['reference_unit_cost' => '3.000000']);
        $service->refresh()->load('consumptions.product');

        $this->assertSame('6.000000', $service->getEstimatedMaterialCost());
    }

    public function test_manipulated_company_id_does_not_allow_cross_company_association(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $product = Product::factory()->forCompany($otherCompany)->consumable()->create();

        $this->expectException(ValidationException::class);

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $product->getKey(),
            'quantity' => '1',
            'company_id' => $company->getKey(),
        ]);
    }

    public function test_composition_sync_runs_in_transaction(): void
    {
        $company = $this->createCompany();
        $service = Service::factory()->forCompany($company)->create();
        $productA = Product::factory()->forCompany($company)->consumable()->create();
        $productB = Product::factory()->forCompany($company)->consumable()->create();

        app(ServiceCompositionService::class)->createConsumption($company, $service, [
            'product_id' => $productA->getKey(),
            'quantity' => '1',
        ]);

        try {
            app(ServiceCompositionService::class)->sync($company, $service, [
                ['product_id' => $productA->getKey(), 'quantity' => '1'],
                ['product_id' => $productB->getKey(), 'quantity' => '0'],
            ]);
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(1, ServiceProductConsumption::query()->where('service_id', $service->getKey())->count());
    }
}
