<?php

namespace Tests\Feature\Sales;

use App\Enums\CompanyModule;
use App\Filament\App\Pages\PointOfSalePage;
use App\Filament\App\Resources\Sales\Pages\ListSales;
use App\Models\Sale;
use Livewire\Livewire;
use Tests\TestCase;

class PointOfSalePageTest extends TestCase
{
    public function test_admin_can_access_point_of_sale_page_when_sales_module_is_enabled(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::Stock->value,
                CompanyModule::Finance->value,
                CompanyModule::Sales->value,
            ],
        ]);
        $user = $this->createCompanyUser($company);

        $this->authenticateForAppTenant($user, $company);

        Livewire::test(PointOfSalePage::class)
            ->assertSuccessful();
    }

    public function test_point_of_sale_page_is_forbidden_without_sales_module(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::Stock->value,
                CompanyModule::Finance->value,
            ],
        ]);
        $user = $this->createCompanyUser($company);

        $this->authenticateForAppTenant($user, $company);

        Livewire::test(PointOfSalePage::class)
            ->assertForbidden();
    }

    public function test_admin_can_list_sales_when_sales_module_is_enabled(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::Stock->value,
                CompanyModule::Finance->value,
                CompanyModule::Sales->value,
            ],
        ]);
        $user = $this->createCompanyUser($company);
        $sale = Sale::factory()->forCompany($company)->create();

        $this->authenticateForAppTenant($user, $company);

        Livewire::test(ListSales::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$sale]);
    }
}
