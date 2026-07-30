<?php

namespace Tests\Feature\MultiTenancy;

use App\Enums\CompanyRole;
use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\Companies\Pages\EditCompany;
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

    public function test_company_user_marked_as_super_admin_cannot_access_admin_panel(): void
    {
        $company = $this->createCompany();
        $companyUser = $this->createCompanyUser($company, [
            'is_super_admin' => true,
        ], CompanyRole::CompanyAdmin);

        $this->actingAs($companyUser)
            ->get('/admin')
            ->assertForbidden();

        $this->assertFalse($companyUser->canAccessPanel(Filament::getPanel('admin')));
        $this->assertFalse($companyUser->isPlatformAdmin());
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

    public function test_super_admin_can_render_company_edit_page(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $company = $this->createCompany([
            'business_profile' => CompanyProfile::Professional,
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);

        Filament::setCurrentPanel('admin');
        $this->actingAs($superAdmin);

        Livewire::test(EditCompany::class, ['record' => $company->getKey()])
            ->assertSuccessful();
    }

    public function test_super_admin_can_update_company_modules_from_edit_page(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $company = $this->createCompany([
            'business_profile' => CompanyProfile::Professional,
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);

        Filament::setCurrentPanel('admin');
        $this->actingAs($superAdmin);

        Livewire::test(EditCompany::class, ['record' => $company->getKey()])
            ->fillForm([
                'name' => $company->name,
                'business_profile' => CompanyProfile::ServicesAndProducts->value,
                'slug' => $company->slug,
                'document' => $company->document,
                'phone' => $company->phone,
                'email' => $company->email,
                'timezone' => $company->timezone,
                'is_active' => true,
                'enabled_modules' => [
                    CompanyModule::Scheduling->value,
                    CompanyModule::Sales->value,
                    CompanyModule::Stock->value,
                    CompanyModule::Finance->value,
                    CompanyModule::WhatsApp->value,
                ],
                'subscription_status' => SubscriptionStatus::Active->value,
                'trial_ends_at' => null,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame([
            CompanyModule::Scheduling->value,
            CompanyModule::Sales->value,
            CompanyModule::Stock->value,
            CompanyModule::Finance->value,
            CompanyModule::WhatsApp->value,
        ], $company->refresh()->enabled_modules);
    }
}
