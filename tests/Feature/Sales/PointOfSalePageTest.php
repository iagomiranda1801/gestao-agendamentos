<?php

namespace Tests\Feature\Sales;

use App\Enums\CompanyModule;
use App\Enums\PaymentMethod;
use App\Enums\SaleItemType;
use App\Filament\App\Pages\PointOfSalePage;
use App\Filament\App\Resources\Sales\Pages\ListSales;
use App\Models\Sale;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class PointOfSalePageTest extends TestCase
{
    use CreatesFinanceFixtures;

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

    public function test_admin_can_complete_a_custom_sale_without_a_client_from_the_point_of_sale(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [
                CompanyModule::Finance->value,
                CompanyModule::Sales->value,
            ],
        ]);
        $user = $this->createCompanyUser($company);
        $account = $this->createFinancialAccount($company);

        $this->authenticateForAppTenant($user, $company);

        Livewire::test(PointOfSalePage::class)
            ->set('data.items', [[
                'item_type' => SaleItemType::Custom->value,
                'name' => 'Honorário avulso',
                'quantity' => '1',
                'unit_price' => '150.00',
                'discount_amount' => '0.00',
            ]])
            ->set('data.payments', [[
                'amount' => '150.00',
                'fee_amount' => '0.00',
                'method' => PaymentMethod::Pix->value,
                'financial_account_id' => $account->getKey(),
                'paid_at' => now()->toDateTimeString(),
            ]])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('sales', [
            'company_id' => $company->getKey(),
            'client_id' => null,
            'final_amount' => '150.00',
        ]);
    }
}
