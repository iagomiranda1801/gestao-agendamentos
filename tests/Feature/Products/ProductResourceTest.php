<?php

namespace Tests\Feature\Products;

use App\Enums\CompanyRole;
use App\Filament\App\Resources\Products\Pages\ListProducts;
use App\Filament\App\Resources\Products\ProductResource;
use App\Models\MeasurementUnit;
use App\Models\Product;
use App\Policies\ProductPolicy;
use App\Services\Product\ProductService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ProductResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MeasurementUnit::factory()->create(['code' => 'unit', 'is_active' => true]);
    }

    public function test_company_admin_can_list_products(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $admin = $this->createCompanyUser($company);
        Product::factory()->forCompany($company)->create(['name' => 'Algodão']);

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(ListProducts::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords(Product::where('company_id', $company->id)->get());
    }

    public function test_manager_can_list_products(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $manager = $this->createCompanyUser($company, role: CompanyRole::Manager);

        $this->authenticateForAppTenant($manager, $company);

        Livewire::test(ListProducts::class)->assertSuccessful();
    }

    public function test_employee_cannot_access_product_resource(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $employee = $this->createCompanyUser($company, role: CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $company);

        Livewire::test(ListProducts::class)->assertForbidden();
    }

    public function test_product_receives_current_company_id(): void
    {
        $company = $this->createCompany();
        $unit = MeasurementUnit::query()->first();

        $product = app(ProductService::class)->create($company, [
            'name' => 'Algodão',
            'measurement_unit_id' => $unit->getKey(),
            'type' => 'consumable',
            'reference_unit_cost' => 0.01,
            'minimum_stock' => 0,
            'tracks_stock' => true,
            'is_active' => true,
        ]);

        $this->assertSame($company->id, $product->company_id);
    }

    public function test_manipulated_company_id_is_ignored_on_create(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $unit = MeasurementUnit::query()->first();

        $product = app(ProductService::class)->create($company, [
            'name' => 'Algodão',
            'measurement_unit_id' => $unit->getKey(),
            'type' => 'consumable',
            'reference_unit_cost' => 0.01,
            'minimum_stock' => 0,
            'tracks_stock' => true,
            'is_active' => true,
            'company_id' => $otherCompany->id,
        ]);

        $this->assertSame($company->id, $product->company_id);
    }

    public function test_product_from_other_company_is_not_listed(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);

        $visible = Product::factory()->forCompany($company)->create(['name' => 'Visivel']);
        $hidden = Product::factory()->forCompany($otherCompany)->create(['name' => 'Oculto']);

        $this->authenticateForAppTenant($admin, $company);

        $records = ProductResource::getEloquentQuery()->get();

        $this->assertTrue($records->contains('id', $visible->id));
        $this->assertFalse($records->contains('id', $hidden->id));
    }

    public function test_product_from_other_company_cannot_be_edited(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra']);
        $admin = $this->createCompanyUser($company);
        $foreign = Product::factory()->forCompany($otherCompany)->create();

        $this->actingAs($admin)
            ->get(ProductResource::getUrl('edit', [
                'tenant' => $company,
                'record' => $foreign,
            ], panel: 'app', tenant: $company))
            ->assertNotFound();
    }

    public function test_product_from_other_company_cannot_be_updated(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $foreign = Product::factory()->forCompany($otherCompany)->create(['name' => 'Original']);
        $unit = MeasurementUnit::query()->first();

        $this->expectException(HttpException::class);

        app(ProductService::class)->update($company, $foreign, [
            'name' => 'Alterado',
            'measurement_unit_id' => $unit->getKey(),
            'type' => 'consumable',
            'reference_unit_cost' => 0.01,
            'minimum_stock' => 0,
            'tracks_stock' => true,
            'is_active' => true,
        ]);
    }

    public function test_duplicate_name_in_same_company_is_prevented(): void
    {
        $company = $this->createCompany();
        $unit = MeasurementUnit::query()->first();

        Product::factory()->forCompany($company)->create(['name' => 'Algodão']);

        $this->expectException(ValidationException::class);

        app(ProductService::class)->create($company, [
            'name' => 'Algodão',
            'measurement_unit_id' => $unit->getKey(),
            'type' => 'consumable',
            'reference_unit_cost' => 0.01,
            'minimum_stock' => 0,
            'tracks_stock' => true,
            'is_active' => true,
        ]);
    }

    public function test_same_name_can_exist_in_different_companies(): void
    {
        $companyA = $this->createCompany();
        $companyB = $this->createCompany();
        $unit = MeasurementUnit::query()->first();

        app(ProductService::class)->create($companyA, [
            'name' => 'Algodão',
            'measurement_unit_id' => $unit->getKey(),
            'type' => 'consumable',
            'reference_unit_cost' => 0.01,
            'minimum_stock' => 0,
            'tracks_stock' => true,
            'is_active' => true,
        ]);

        $productB = app(ProductService::class)->create($companyB, [
            'name' => 'Algodão',
            'measurement_unit_id' => $unit->getKey(),
            'type' => 'consumable',
            'reference_unit_cost' => 0.01,
            'minimum_stock' => 0,
            'tracks_stock' => true,
            'is_active' => true,
        ]);

        $this->assertSame('Algodão', $productB->name);
    }

    public function test_duplicate_sku_in_same_company_is_prevented(): void
    {
        $company = $this->createCompany();
        $unit = MeasurementUnit::query()->first();

        Product::factory()->forCompany($company)->create(['sku' => 'SKU-001']);

        $this->expectException(ValidationException::class);

        app(ProductService::class)->create($company, [
            'name' => 'Outro Produto',
            'sku' => 'SKU-001',
            'measurement_unit_id' => $unit->getKey(),
            'type' => 'consumable',
            'reference_unit_cost' => 0.01,
            'minimum_stock' => 0,
            'tracks_stock' => true,
            'is_active' => true,
        ]);
    }

    public function test_negative_cost_is_prevented(): void
    {
        $company = $this->createCompany();
        $unit = MeasurementUnit::query()->first();

        $this->expectException(ValidationException::class);

        app(ProductService::class)->create($company, [
            'name' => 'Produto Negativo',
            'measurement_unit_id' => $unit->getKey(),
            'type' => 'consumable',
            'reference_unit_cost' => -1,
            'minimum_stock' => 0,
            'tracks_stock' => true,
            'is_active' => true,
        ]);
    }

    public function test_product_can_be_deactivated(): void
    {
        $company = $this->createCompany();
        $product = Product::factory()->forCompany($company)->create(['is_active' => true]);

        app(ProductService::class)->changeStatus($company, $product, false);

        $this->assertFalse($product->fresh()->is_active);
    }

    public function test_product_resource_has_no_delete_action(): void
    {
        $this->assertFalse((new ProductPolicy)->delete(
            $this->createCompanyUser($this->createCompany()),
            Product::factory()->make(),
        ));
    }
}
