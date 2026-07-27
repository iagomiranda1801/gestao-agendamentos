<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyModule;
use App\Enums\CompanyRole;
use App\Enums\SubscriptionStatus;
use App\Filament\App\Pages\Dashboard;
use App\Filament\App\Pages\SubscriptionExpiredPage;
use Tests\TestCase;

class TrialMiddlewareTest extends TestCase
{
    public function test_expired_trial_redirects_to_subscription_page(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->subDay(),
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(Dashboard::getUrl(['tenant' => $company]))
            ->assertRedirect(SubscriptionExpiredPage::getUrl(['tenant' => $company]));
    }

    public function test_active_trial_allows_dashboard_access(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(5),
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(Dashboard::getUrl(['tenant' => $company]))
            ->assertOk();
    }

    public function test_subscription_page_is_accessible_when_trial_expired(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->subDay(),
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(SubscriptionExpiredPage::getUrl(['tenant' => $company]))
            ->assertOk();
    }
}
