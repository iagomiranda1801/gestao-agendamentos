<?php

namespace Tests;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');
    }

    protected function createSuperAdmin(array $attributes = []): User
    {
        return User::factory()->superAdmin()->create($attributes);
    }

    protected function createCompanyUser(Company $company, array $userAttributes = [], CompanyRole $role = CompanyRole::CompanyAdmin, bool $isActive = true): User
    {
        $user = User::factory()->create($userAttributes);

        $company->users()->attach($user, [
            'role' => $role->value,
            'is_active' => $isActive,
        ]);

        return $user;
    }

    protected function createCompany(array $attributes = []): Company
    {
        return Company::factory()->create($attributes);
    }

    protected function authenticateForAppTenant(User $user, Company $company): void
    {
        $this->actingAs($user);

        app()->setLocale('pt_BR');

        $panel = Filament::getPanel('app');
        Filament::setCurrentPanel($panel);
        $panel->boot();
        Filament::setServingStatus(true);
        Filament::setTenant($company, isQuiet: true);
    }
}
