<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyModule;
use App\Enums\CompanyRole;
use App\Filament\App\Pages\KitchenDisplayPage;
use App\Filament\App\Pages\OrderSettingsPage;
use App\Filament\App\Resources\OnlineMenus\OnlineMenuResource;
use App\Filament\App\Resources\Orders\OrderResource;
use Filament\Facades\Filament;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\TestCase;

class OrderModuleGatingTest extends TestCase
{
    use CreatesOrderFixtures;

    public function test_orders_surfaces_are_hidden_without_module(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
        ]);
        $admin = $this->createCompanyUser($company);

        $this->authenticateForAppTenant($admin, $company);
        Filament::setCurrentPanel('app');

        $this->assertFalse(OrderResource::canViewAny());
        $this->assertFalse(OrderResource::shouldRegisterNavigation());
        $this->assertFalse(OnlineMenuResource::canViewAny());
        $this->assertFalse(KitchenDisplayPage::canAccess());
        $this->assertFalse(OrderSettingsPage::canAccess());
    }

    public function test_orders_surfaces_are_visible_with_module(): void
    {
        $setup = $this->createRestaurantSetup();
        $admin = $this->createCompanyUser($setup['company']);

        $this->authenticateForAppTenant($admin, $setup['company']);
        Filament::setCurrentPanel('app');

        $this->assertTrue(OrderResource::canViewAny());
        $this->assertTrue(OrderResource::shouldRegisterNavigation());
        $this->assertTrue(OnlineMenuResource::canViewAny());
        $this->assertTrue(KitchenDisplayPage::canAccess());
        $this->assertTrue(OrderSettingsPage::canAccess());
    }

    public function test_employee_can_open_kitchen_but_not_settings(): void
    {
        $setup = $this->createRestaurantSetup();
        $employee = $this->createCompanyUser($setup['company'], role: CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $setup['company']);
        Filament::setCurrentPanel('app');

        $this->assertTrue(KitchenDisplayPage::canAccess());
        $this->assertFalse(OrderSettingsPage::canAccess());
    }
}
