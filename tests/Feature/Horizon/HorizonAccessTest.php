<?php

namespace Tests\Feature\Horizon;

use App\Enums\CompanyRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class HorizonAccessTest extends TestCase
{
    public function test_super_admin_can_view_horizon(): void
    {
        $admin = $this->createSuperAdmin();

        $this->assertTrue(Gate::forUser($admin)->allows('viewHorizon'));

        $this->actingAs($admin)
            ->get('/horizon')
            ->assertOk();
    }

    public function test_regular_user_cannot_view_horizon(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));

        $this->actingAs($user)
            ->get('/horizon')
            ->assertForbidden();
    }

    public function test_inactive_super_admin_cannot_view_horizon(): void
    {
        $user = User::factory()->superAdmin()->inactive()->create();

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));

        $this->actingAs($user)
            ->get('/horizon')
            ->assertForbidden();
    }

    public function test_company_admin_cannot_view_horizon(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->assertFalse(Gate::forUser($user)->allows('viewHorizon'));

        $this->actingAs($user)
            ->get('/horizon')
            ->assertForbidden();
    }
}
