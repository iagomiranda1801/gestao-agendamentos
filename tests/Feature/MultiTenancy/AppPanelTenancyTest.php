<?php

namespace Tests\Feature\MultiTenancy;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Tests\TestCase;

class AppPanelTenancyTest extends TestCase
{
    public function test_super_admin_cannot_access_app_company_tenant(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $company = $this->createCompany(['slug' => 'estudio-ana']);

        $this->actingAs($superAdmin)
            ->get('/app/empresa/'.$company->slug)
            ->assertForbidden();
    }

    public function test_linked_admin_can_access_their_company(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $admin = $this->createCompanyUser($company, [
            'email' => 'ana@estudioana.test',
        ]);

        $this->actingAs($admin)
            ->get('/app/empresa/'.$company->slug)
            ->assertOk();
    }

    public function test_admin_cannot_access_company_without_membership(): void
    {
        $linkedCompany = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra-empresa']);
        $admin = $this->createCompanyUser($linkedCompany);

        $this->actingAs($admin)
            ->get('/app/empresa/'.$otherCompany->slug)
            ->assertNotFound();
    }

    public function test_inactive_membership_prevents_access(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin, isActive: false);

        $this->actingAs($admin)
            ->get('/app/empresa/'.$company->slug)
            ->assertForbidden();
    }

    public function test_inactive_company_prevents_access(): void
    {
        $company = $this->createCompany([
            'slug' => 'estudio-ana',
            'is_active' => false,
        ]);
        $admin = $this->createCompanyUser($company);

        $this->actingAs($admin)
            ->get('/app/empresa/'.$company->slug)
            ->assertForbidden();
    }

    public function test_user_with_active_tenant_gets_not_found_for_inaccessible_tenant(): void
    {
        $linkedCompany = $this->createCompany(['slug' => 'estudio-ana']);
        $otherCompany = $this->createCompany(['slug' => 'outra-empresa', 'is_active' => false]);
        $admin = $this->createCompanyUser($linkedCompany);

        $this->actingAs($admin)
            ->get('/app/empresa/'.$otherCompany->slug)
            ->assertNotFound();
    }

    public function test_inactive_user_cannot_access_app_panel(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $user = User::factory()->inactive()->create();

        $company->users()->attach($user, [
            'role' => CompanyRole::CompanyAdmin->value,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->get('/app/empresa/'.$company->slug)
            ->assertForbidden();
    }
}
