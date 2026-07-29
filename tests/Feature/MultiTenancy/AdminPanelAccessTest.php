<?php

namespace Tests\Feature\MultiTenancy;

use App\Enums\CompanyRole;
use App\Filament\Admin\Resources\Companies\Pages\ListCompanies;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class AdminPanelAccessTest extends TestCase
{
    public function test_super_admin_can_access_admin_panel(): void
    {
        $superAdmin = $this->createSuperAdmin();

        $this->actingAs($superAdmin)
            ->get('/admin')
            ->assertOk();
    }

    public function test_regular_user_cannot_access_admin_panel(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_company_admin_can_access_app_but_not_admin_panel(): void
    {
        $company = $this->createCompany();
        $companyAdmin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->actingAs($companyAdmin)
            ->get('/admin')
            ->assertForbidden();

        $this->assertFalse($companyAdmin->canAccessPanel(Filament::getPanel('admin')));
        $this->assertTrue($companyAdmin->canAccessPanel(Filament::getPanel('app')));
    }

    public function test_inactive_user_cannot_access_admin_panel(): void
    {
        $user = User::factory()->superAdmin()->inactive()->create();

        $this->actingAs($user)
            ->get('/admin')
            ->assertForbidden();
    }

    public function test_regular_user_cannot_access_company_resource(): void
    {
        $user = User::factory()->create();

        Filament::setCurrentPanel('admin');

        $this->actingAs($user);

        Livewire::test(ListCompanies::class)
            ->assertForbidden();
    }

    public function test_regular_user_cannot_access_user_resource(): void
    {
        $user = User::factory()->create();

        Filament::setCurrentPanel('admin');

        $this->actingAs($user);

        Livewire::test(ListUsers::class)
            ->assertForbidden();
    }

    public function test_super_admin_can_access_company_resource(): void
    {
        $superAdmin = $this->createSuperAdmin();

        Filament::setCurrentPanel('admin');

        $this->actingAs($superAdmin);

        Livewire::test(ListCompanies::class)
            ->assertSuccessful();
    }

    public function test_super_admin_can_access_company_resource_with_records(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->createCompany();

        Filament::setCurrentPanel('admin');

        $this->actingAs($superAdmin);

        Livewire::test(ListCompanies::class)
            ->assertSuccessful();
    }
}
