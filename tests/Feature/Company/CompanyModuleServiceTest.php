<?php

namespace Tests\Feature\Company;

use App\Enums\CompanyModule;
use App\Enums\SubscriptionStatus;
use App\Models\Company;
use App\Services\Company\CompanyModuleService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompanyModuleServiceTest extends TestCase
{
    public function test_sync_modules_persists_selected_modules(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
        ]);

        app(CompanyModuleService::class)->syncModules($company, [
            CompanyModule::Scheduling,
            CompanyModule::Stock,
        ]);

        $company->refresh();

        $this->assertTrue(app(CompanyModuleService::class)->hasModule($company, CompanyModule::Scheduling));
        $this->assertTrue(app(CompanyModuleService::class)->hasModule($company, CompanyModule::Stock));
        $this->assertFalse(app(CompanyModuleService::class)->hasModule($company, CompanyModule::Finance));
    }

    public function test_sync_modules_requires_at_least_one_module(): void
    {
        $company = $this->createCompany();

        $this->expectException(ValidationException::class);

        app(CompanyModuleService::class)->syncModules($company, []);
    }

    public function test_marketing_automatically_includes_operational_whatsapp(): void
    {
        $company = $this->createCompany();

        app(CompanyModuleService::class)->syncModules($company, [CompanyModule::Marketing]);

        $this->assertTrue(app(CompanyModuleService::class)->hasModule($company->refresh(), CompanyModule::WhatsApp));
        $this->assertTrue(app(CompanyModuleService::class)->hasModule($company, CompanyModule::Marketing));
    }

    public function test_trial_access_is_allowed_before_expiration(): void
    {
        $company = Company::factory()->create([
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(3),
            'enabled_modules' => [CompanyModule::Scheduling->value],
        ]);

        $service = app(CompanyModuleService::class);

        $this->assertTrue($service->isTrialActive($company));
        $this->assertTrue($service->isAccessAllowed($company));
        $this->assertSame(3, $service->trialDaysRemaining($company));
    }

    public function test_expired_trial_blocks_access(): void
    {
        $company = Company::factory()->create([
            'subscription_status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->subDay(),
            'enabled_modules' => [CompanyModule::Scheduling->value],
        ]);

        $service = app(CompanyModuleService::class);

        $this->assertFalse($service->isTrialActive($company));
        $this->assertFalse($service->isAccessAllowed($company));
    }

    public function test_active_subscription_allows_access(): void
    {
        $company = Company::factory()->create([
            'subscription_status' => SubscriptionStatus::Active,
            'trial_ends_at' => null,
        ]);

        $this->assertTrue(app(CompanyModuleService::class)->isAccessAllowed($company));
    }
}
