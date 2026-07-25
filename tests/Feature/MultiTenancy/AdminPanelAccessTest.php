<?php

namespace Tests\Feature\MultiTenancy;

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
}
