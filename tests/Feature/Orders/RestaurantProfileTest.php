<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Models\ModulePrice;
use App\Services\Company\CompanyModuleService;
use App\Services\Company\CompanyProvisioningService;
use Database\Seeders\ModulePriceSeeder;
use Tests\TestCase;

class RestaurantProfileTest extends TestCase
{
    public function test_restaurant_profile_defaults_to_orders_and_whatsapp(): void
    {
        $this->assertSame('Restaurante ou food service', CompanyProfile::Restaurant->label());
        $this->assertContains(CompanyModule::Orders, CompanyProfile::Restaurant->defaultModules());
        $this->assertContains(CompanyModule::WhatsApp, CompanyProfile::Restaurant->defaultModules());
        $this->assertNotContains(CompanyModule::Finance, CompanyProfile::Restaurant->defaultModules());
        $this->assertNotContains(CompanyModule::Sales, CompanyProfile::Restaurant->defaultModules());
        $this->assertNotContains(CompanyModule::Stock, CompanyProfile::Restaurant->defaultModules());
    }

    public function test_orders_module_is_labeled_pedidos(): void
    {
        $this->assertSame('Pedidos', CompanyModule::Orders->label());
        $this->assertArrayHasKey(CompanyModule::Orders->value, CompanyModule::options());
    }

    public function test_provisioning_restaurant_creates_order_settings(): void
    {
        $result = app(CompanyProvisioningService::class)->provision([
            'name' => 'Cantina Piloto',
            'slug' => 'cantina-piloto',
            'business_profile' => CompanyProfile::Restaurant,
            'admin_name' => 'Ana Cozinha',
            'admin_email' => 'ana@cantina.test',
            'admin_password' => 'Password123!',
        ]);

        $company = $result['company'];

        $this->assertSame(CompanyProfile::Restaurant, $company->business_profile);
        $this->assertTrue(app(CompanyModuleService::class)->hasModule($company, CompanyModule::Orders));
        $this->assertTrue(app(CompanyModuleService::class)->hasModule($company, CompanyModule::WhatsApp));
        $this->assertNotNull($company->orderSetting);
        $this->assertTrue($company->orderSetting->dine_in_enabled);
        $this->assertSame(
            ['Lanches', 'Bebidas', 'Sobremesas', 'Combos', 'Outros'],
            $company->menuCategories()->orderBy('sort_order')->pluck('name')->all(),
        );
    }

    public function test_module_price_seeder_includes_orders_at_entry_price(): void
    {
        $this->seed(ModulePriceSeeder::class);

        $monthly = ModulePrice::query()
            ->where('module', CompanyModule::Orders->value)
            ->where('interval', 'monthly')
            ->first();

        $this->assertNotNull($monthly);
        $this->assertSame(4900, $monthly->price_cents);
    }
}
