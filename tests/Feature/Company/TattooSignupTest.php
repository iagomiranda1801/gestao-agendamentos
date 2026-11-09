<?php

namespace Tests\Feature\Company;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Enums\SubscriptionStatus;
use App\Livewire\Signup\TattooSignup;
use App\Models\Company;
use App\Models\ModulePrice;
use App\Models\User;
use App\Services\Company\CompanySubscriptionService;
use App\Services\Company\TattooSignupService;
use App\Support\TattooLaunchPlan;
use Database\Seeders\ModulePriceSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class TattooSignupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(ModulePriceSeeder::class);
        config(['subscriptions.tattoflow_launch_price_cents' => 7990]);
        URL::forceRootUrl('http://estudio.localhost');
    }

    public function test_landing_and_dedicated_signup_show_the_real_offer(): void
    {
        foreach (['/', '/cadastro'] as $path) {
            $this->get('http://estudio.localhost'.$path)
                ->assertOk()
                ->assertSee('R$ 79,90')
                ->assertSee('wire:submit="submit"', false)
                ->assertSee('7 dias')
                ->assertSee('PIX com confirmação');
        }
    }

    public function test_quick_signup_creates_a_tattoo_tenant_with_the_full_package_and_logs_in(): void
    {
        $this->freezeTime();
        request()->headers->set('HOST', 'estudio.localhost');

        Livewire::test(TattooSignup::class)
            ->set('companyName', 'Estúdio Nova')
            ->set('name', 'Ana Silva')
            ->set('email', 'ANA@ESTUDIO.TEST')
            ->set('phone', '(11) 99999-1234')
            ->set('password', 'Password123!')
            ->call('submit')
            ->assertHasNoErrors()
            ->assertRedirect('http://estudio.localhost/painel/empresa/estudio-nova');

        $company = Company::query()->where('slug', 'estudio-nova')->firstOrFail();
        $user = User::query()->where('email', 'ana@estudio.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(CompanyProfile::TattooStudio, $company->business_profile);
        $this->assertSame(TattooLaunchPlan::moduleValues(), $company->enabled_modules);
        $this->assertSame(7990, $company->subscription_price_cents);
        $this->assertSame(TattooLaunchPlan::KEY, $company->subscription_plan);
        $this->assertSame(BillingInterval::Monthly, $company->billing_interval);
        $this->assertSame(SubscriptionStatus::Trial, $company->subscription_status);
        $this->assertTrue($company->trial_ends_at->equalTo(now()->addDays(7)->startOfSecond()));
        $this->assertSame('11999991234', $company->phone);
        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertTrue($user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin));
        $this->assertNotNull($company->schedulingSetting);
        $this->assertTrue($company->schedulingSetting->whatsapp_bot_enabled);
        $this->assertDatabaseCount('platform_invoices', 0);
    }

    public function test_invalid_or_duplicate_registration_does_not_create_a_company(): void
    {
        request()->headers->set('HOST', 'estudio.localhost');
        User::factory()->create(['email' => 'existing@estudio.test']);

        Livewire::test(TattooSignup::class)
            ->set('companyName', 'Estúdio inválido')
            ->set('name', 'Ana Silva')
            ->set('email', 'existing@estudio.test')
            ->set('phone', '123')
            ->set('password', 'short')
            ->call('submit')
            ->assertHasErrors(['email', 'phone', 'password']);

        $this->assertDatabaseCount('companies', 0);
        $this->assertGuest();
    }

    public function test_registration_is_throttled_on_livewire_submissions(): void
    {
        request()->headers->set('HOST', 'estudio.localhost');
        $component = Livewire::test(TattooSignup::class);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->call('submit');
        }
        $component->call('submit')->assertHasErrors(['signup']);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_launch_price_is_used_for_invoices_and_renewals_even_after_catalog_changes(): void
    {
        $result = app(TattooSignupService::class)->register([
            'company_name' => 'Estúdio Plano', 'name' => 'Leo Admin',
            'email' => 'leo@plano.test', 'phone' => '11999991234', 'password' => 'Password123!',
        ]);
        $company = $result['company'];
        $subscriptions = app(CompanySubscriptionService::class);

        ModulePrice::query()->update(['price_cents' => 15000]);
        config(['subscriptions.tattoflow_launch_price_cents' => 9990]);
        $this->assertSame(7990, $subscriptions->quoteForCompany($company));
        $invoice = $subscriptions->issueInvoice($company);
        $this->assertSame(7990, $invoice->amount_cents);
        $this->assertSame(7990, collect($invoice->items)->sum('price_cents'));
        $this->assertSame(SubscriptionStatus::Trial, $company->refresh()->subscription_status);

        $subscriptions->payInvoice($invoice);
        $this->assertSame(SubscriptionStatus::Active, $company->refresh()->subscription_status);
        $this->travelTo($company->current_period_end->copy()->subDay());
        $this->assertSame(1, $subscriptions->issueDueInvoices());
        $renewal = $company->platformInvoices()->latest('id')->firstOrFail();
        $this->assertSame(7990, $renewal->amount_cents);
    }

    public function test_studios_with_the_same_name_receive_different_urls(): void
    {
        $signup = app(TattooSignupService::class);
        foreach (['first', 'second'] as $name) {
            $signup->register([
                'company_name' => 'Estúdio Arte', 'name' => 'Admin',
                'email' => $name.'@studio.test', 'phone' => '11999991234', 'password' => 'Password123!',
            ]);
        }
        $this->assertDatabaseHas('companies', ['slug' => 'estudio-arte']);
        $this->assertDatabaseHas('companies', ['slug' => 'estudio-arte-1']);
    }

    public function test_other_companies_keep_module_catalog_pricing(): void
    {
        $company = $this->createCompany([
            'business_profile' => CompanyProfile::TattooStudio,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
            'billing_interval' => BillingInterval::Monthly,
        ]);
        $subscriptions = app(CompanySubscriptionService::class);
        $this->assertSame(9980, $subscriptions->quoteForCompany($company));
        $this->assertSame(9980, $subscriptions->issueInvoice($company)->amount_cents);
    }
}
