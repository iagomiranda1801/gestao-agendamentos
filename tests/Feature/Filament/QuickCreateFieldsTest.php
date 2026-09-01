<?php

namespace Tests\Feature\Filament;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\ProductType;
use App\Filament\App\Support\QuickCreateFields;
use App\Models\Client;
use App\Models\Product;
use App\Models\Service;
use Tests\TestCase;

class QuickCreateFieldsTest extends TestCase
{
    public function test_quick_client_create_persists_for_tenant(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        $id = QuickCreateFields::createClient([
            'name' => 'Maria Nova',
            'phone' => '(11) 98888-7777',
            'email' => 'maria@example.test',
        ]);

        $this->assertDatabaseHas('clients', [
            'id' => $id,
            'company_id' => $company->getKey(),
            'name' => 'Maria Nova',
            'email' => 'maria@example.test',
        ]);
    }

    public function test_quick_client_create_stores_plate_for_car_wash(): void
    {
        $company = $this->createCompany(['business_profile' => CompanyProfile::CarWash]);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        $id = QuickCreateFields::createClient([
            'name' => 'João do Carro',
            'phone' => '11999990000',
            'vehicle_plate' => 'ABC1D23',
        ]);

        $client = Client::query()->findOrFail($id);
        $this->assertSame('ABC1D23', $client->vehicle_plate);
    }

    public function test_quick_service_is_bookable_sellable_and_not_online(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        $id = QuickCreateFields::createService([
            'name' => 'Corte express',
            'price' => '80.00',
            'duration_minutes' => 45,
        ]);

        $service = Service::query()->findOrFail($id);
        $this->assertTrue($service->is_bookable);
        $this->assertTrue($service->is_sellable);
        $this->assertFalse($service->is_online_booking_enabled);
        $this->assertSame(45, $service->duration_minutes);
        $this->assertSame('80.00', $service->price);
    }

    public function test_quick_service_is_not_sellable_without_sales_module(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::Finance->value],
        ]);
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        $id = QuickCreateFields::createService([
            'name' => 'Consulta',
            'price' => '120.00',
            'duration_minutes' => 30,
        ]);

        $service = Service::query()->findOrFail($id);
        $this->assertFalse($service->is_sellable);
        $this->assertTrue($service->is_bookable);
    }

    public function test_quick_product_is_sale_type_and_sellable(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($admin, $company);

        $id = QuickCreateFields::createProduct([
            'name' => 'Shampoo loja',
            'sale_price' => '35.90',
        ]);

        $product = Product::query()->findOrFail($id);
        $this->assertSame(ProductType::Sale, $product->type);
        $this->assertTrue($product->is_sellable);
        $this->assertFalse($product->tracks_stock);
        $this->assertSame('35.90', $product->sale_price);
    }
}
