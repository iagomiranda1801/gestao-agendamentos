<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyModule;
use App\Enums\CompanyRole;
use App\Filament\App\Resources\Clients\ClientResource;
use App\Filament\App\Resources\Products\ProductResource;
use Filament\Facades\Filament;
use Tests\TestCase;

class ModuleNavigationTest extends TestCase
{
    public function test_scheduling_only_tenant_cannot_view_stock_resource(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        Filament::setCurrentPanel('app');

        $this->assertTrue(ClientResource::canViewAny());
        $this->assertFalse(ProductResource::canViewAny());
        $this->assertFalse(ProductResource::shouldRegisterNavigation());
    }

    public function test_full_modules_tenant_can_view_stock_resource(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::Stock->value,
                CompanyModule::Finance->value,
            ],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        Filament::setCurrentPanel('app');

        $this->assertTrue(ProductResource::canViewAny());
        $this->assertTrue(ProductResource::shouldRegisterNavigation());
    }
}
