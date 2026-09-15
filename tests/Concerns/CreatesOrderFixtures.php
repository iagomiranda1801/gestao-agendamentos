<?php

namespace Tests\Concerns;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Models\Company;
use App\Models\Product;
use App\Services\Orders\CompanyOrderSettingService;

trait CreatesOrderFixtures
{
    /**
     * @param  array<string, mixed>  $companyAttributes
     * @param  array<string, mixed>  $settingAttributes
     * @return array{company: Company, burger: Product, soda: Product}
     */
    protected function createRestaurantSetup(array $companyAttributes = [], array $settingAttributes = []): array
    {
        $company = $this->createCompany(array_merge([
            'business_profile' => CompanyProfile::Restaurant,
            'enabled_modules' => [CompanyModule::Orders->value, CompanyModule::WhatsApp->value],
            'slug' => 'cantina-piloto',
        ], $companyAttributes));

        app(CompanyOrderSettingService::class)->update($company, array_merge([
            'online_ordering_enabled' => true,
            'pickup_enabled' => true,
            'delivery_enabled' => true,
            'dine_in_enabled' => true,
            'delivery_fee_cents' => 800,
            'min_order_cents' => 0,
        ], $settingAttributes));

        $burger = Product::factory()->forCompany($company)->onlineMenu('Lanches')->create([
            'name' => 'X-Burguer',
            'sale_price' => 25.00,
        ]);
        $soda = Product::factory()->forCompany($company)->onlineMenu('Bebidas')->create([
            'name' => 'Refrigerante',
            'sale_price' => 8.00,
        ]);

        return [
            'company' => $company->fresh(['orderSetting']),
            'burger' => $burger,
            'soda' => $soda,
        ];
    }
}
