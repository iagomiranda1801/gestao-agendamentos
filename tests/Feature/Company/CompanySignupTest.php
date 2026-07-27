<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyModule;
use App\Enums\CompanyRole;
use App\Enums\SubscriptionStatus;
use App\Filament\App\Pages\Dashboard;
use App\Livewire\Signup\CompanySignupWizard;
use App\Models\Company;
use App\Models\User;
use App\Services\Company\CompanyProvisioningService;
use Livewire\Livewire;
use Tests\TestCase;

class CompanySignupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_signup_page_is_accessible(): void
    {
        $this->get(route('signup.company'))
            ->assertOk();
    }

    public function test_provisioning_service_creates_company_user_and_trial(): void
    {
        $result = app(CompanyProvisioningService::class)->provision([
            'name' => 'Studio Teste',
            'slug' => 'studio-teste',
            'email' => 'contato@studio-teste.test',
            'enabled_modules' => [CompanyModule::Scheduling->value],
            'admin_name' => 'Admin Teste',
            'admin_email' => 'admin@studio-teste.test',
            'admin_password' => 'Password123!',
        ]);

        $company = $result['company'];
        $user = $result['user'];

        $this->assertInstanceOf(Company::class, $company);
        $this->assertSame(SubscriptionStatus::Trial, $company->subscription_status);
        $this->assertNotNull($company->trial_ends_at);
        $this->assertTrue($company->trial_ends_at->isFuture());
        $this->assertSame(['scheduling'], $company->enabled_modules);
        $this->assertTrue($user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin));
        $this->assertNotNull($company->schedulingSetting);
    }

    public function test_signup_wizard_creates_tenant_and_redirects(): void
    {
        Livewire::test(CompanySignupWizard::class)
            ->set('companyName', 'Salão Nova')
            ->set('companySlug', 'salao-nova')
            ->set('companyEmail', 'contato@salao-nova.test')
            ->set('selectedModules', [CompanyModule::Scheduling->value])
            ->call('goToModulesStep')
            ->call('goToAdminStep')
            ->set('adminName', 'Maria Admin')
            ->set('adminEmail', 'maria@salao-nova.test')
            ->set('adminPassword', 'Password123!')
            ->set('adminPasswordConfirmation', 'Password123!')
            ->call('goToReviewStep')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect(Dashboard::getUrl(['tenant' => Company::query()->where('slug', 'salao-nova')->first()]));

        $company = Company::query()->where('slug', 'salao-nova')->first();
        $user = User::query()->where('email', 'maria@salao-nova.test')->first();

        $this->assertNotNull($company);
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);
    }
}
