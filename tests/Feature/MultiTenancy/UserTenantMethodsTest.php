<?php

namespace Tests\Feature\MultiTenancy;

use App\Enums\CompanyRole;
use App\Models\User;
use Filament\Facades\Filament;
use Tests\TestCase;

class UserTenantMethodsTest extends TestCase
{
    public function test_get_tenants_does_not_return_inactive_companies(): void
    {
        $user = $this->createCompanyUser($this->createCompany(['slug' => 'ativa', 'is_active' => true]));
        $inactiveCompany = $this->createCompany(['slug' => 'inativa', 'is_active' => false]);

        $user->companies()->attach($inactiveCompany->id, [
            'role' => CompanyRole::Employee->value,
            'is_active' => true,
        ]);

        Filament::setCurrentPanel('app');

        $tenants = $user->getTenants(Filament::getCurrentPanel());

        $this->assertCount(1, $tenants);
        $this->assertTrue($tenants->first()->is($user->companies()->first()));
    }

    public function test_get_tenants_does_not_return_inactive_memberships(): void
    {
        $activeCompany = $this->createCompany(['slug' => 'ativa']);
        $inactiveMembershipCompany = $this->createCompany(['slug' => 'vinculo-inativo']);

        $user = User::factory()->create();

        $user->companies()->attach($activeCompany, [
            'role' => CompanyRole::Employee->value,
            'is_active' => true,
        ]);

        $user->companies()->attach($inactiveMembershipCompany, [
            'role' => CompanyRole::Employee->value,
            'is_active' => false,
        ]);

        Filament::setCurrentPanel('app');

        $tenants = $user->getTenants(Filament::getCurrentPanel());

        $this->assertCount(1, $tenants);
        $this->assertSame('ativa', $tenants->first()->slug);
    }

    public function test_can_access_tenant_validates_membership_in_database(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ana']);
        $user = $this->createCompanyUser($company);

        $this->assertTrue($user->canAccessTenant($company));

        $user->companies()->updateExistingPivot($company->id, [
            'is_active' => false,
        ]);

        $company->refresh();

        $this->assertFalse($user->canAccessTenant($company));
    }

    public function test_super_admin_does_not_get_app_tenants(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $this->createCompany(['slug' => 'empresa-a', 'is_active' => true]);
        $this->createCompany(['slug' => 'empresa-b', 'is_active' => true]);
        $this->createCompany(['slug' => 'empresa-inativa', 'is_active' => false]);

        Filament::setCurrentPanel('app');

        $tenants = $superAdmin->getTenants(Filament::getCurrentPanel());

        $this->assertCount(0, $tenants);
    }
}
