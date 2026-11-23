<?php

namespace Tests\Feature\Company;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Enums\CompanyRole;
use App\Enums\SubscriptionStatus;
use App\Filament\App\Pages\Dashboard;
use App\Livewire\Signup\BellanaSignup;
use App\Models\Company;
use App\Models\ModulePrice;
use App\Models\User;
use App\Services\Company\BellanaSignupService;
use App\Services\Company\CompanySubscriptionService;
use App\Support\BellanaLaunchPlan;
use App\Support\Segment;
use Database\Seeders\ModulePriceSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class BellanaSignupTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(ModulePriceSeeder::class);
        config(['subscriptions.bellana_launch_price_cents' => 7990]);
        URL::forceRootUrl('http://salao.localhost');
        request()->headers->set('HOST', 'salao.localhost');
    }

    public function test_signup_page_shows_the_launch_offer(): void
    {
        $this->get(route('bellana.signup'))
            ->assertOk()
            ->assertSee('R$ 79,90')
            ->assertSee('wire:submit="submit"', false)
            ->assertSee('7 dias')
            ->assertSee('PIX com confirmação');
    }

    public function test_quick_signup_creates_a_salon_with_the_launch_plan_and_logs_in(): void
    {
        $this->freezeTime();

        $component = Livewire::test(BellanaSignup::class)
            ->set('companyName', 'Studio Bela')
            ->set('name', 'Carla Souza')
            ->set('email', 'CARLA@BELA.TEST')
            ->set('phone', '(11) 99999-1234')
            ->set('password', 'Password123!')
            ->call('submit')
            ->assertHasNoErrors();

        $company = Company::query()->where('slug', 'studio-bela')->firstOrFail();
        $component->assertRedirect(Dashboard::getUrl(['tenant' => $company], panel: Segment::panelId('salon')));

        $user = User::query()->where('email', 'carla@bela.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame(CompanyProfile::Salon, $company->business_profile);
        $this->assertSame(BellanaLaunchPlan::moduleValues(), $company->enabled_modules);
        $this->assertSame(BellanaLaunchPlan::KEY, $company->subscription_plan);
        $this->assertSame(7990, $company->subscription_price_cents);
        $this->assertSame(BillingInterval::Monthly, $company->billing_interval);
        $this->assertSame(SubscriptionStatus::Trial, $company->subscription_status);
        $this->assertTrue($company->trial_ends_at->equalTo(now()->addDays(7)->startOfSecond()));
        $this->assertSame('11999991234', $company->phone);
        $this->assertTrue(Hash::check('Password123!', $user->password));
        $this->assertTrue($user->hasActiveRoleInCompany($company, CompanyRole::CompanyAdmin));
        $this->assertTrue($company->schedulingSetting->whatsapp_bot_enabled);
        $this->assertFalse((bool) $company->schedulingSetting->beauty_ai_enabled);
        $this->assertDatabaseCount('platform_invoices', 0);
    }

    public function test_quick_signup_is_not_available_outside_the_salon_domain(): void
    {
        config(['segments.salon.enabled' => false]);
        $this->get(rtrim((string) config('app.url'), '/').'/bellana/cadastro')->assertNotFound();

        URL::forceRootUrl('http://localhost');
        request()->headers->set('HOST', 'localhost');
        Livewire::test(BellanaSignup::class)->assertNotFound();
    }

    public function test_invalid_or_duplicate_registration_does_not_create_a_company(): void
    {
        User::factory()->create(['email' => 'existing@bela.test']);

        Livewire::test(BellanaSignup::class)
            ->set('companyName', 'Salão inválido')
            ->set('name', 'Carla Souza')
            ->set('email', 'existing@bela.test')
            ->set('phone', '123')
            ->set('password', 'short')
            ->call('submit')
            ->assertHasErrors(['email', 'phone', 'password']);

        $this->assertDatabaseCount('companies', 0);
        $this->assertGuest();
    }

    public function test_registration_is_throttled(): void
    {
        $component = Livewire::test(BellanaSignup::class);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $component->call('submit');
        }
        $component->call('submit')->assertHasErrors(['signup']);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_launch_price_is_used_for_invoices_and_renewals_even_after_catalog_changes(): void
    {
        $company = app(BellanaSignupService::class)->register([
            'company_name' => 'Salão Plano', 'name' => 'Bia Admin',
            'email' => 'bia@plano.test', 'phone' => '11999991234', 'password' => 'Password123!',
        ])['company'];
        $subscriptions = app(CompanySubscriptionService::class);

        $this->assertSame(7990, $subscriptions->quoteForCompany($company));
        $this->assertContains('bellana_launch_adjustment', array_column(
            $subscriptions->quoteItemsForCompany($company, BillingInterval::Monthly), 'module',
        ));

        ModulePrice::query()->update(['price_cents' => 15000]);
        config(['subscriptions.bellana_launch_price_cents' => 9990]);
        $this->assertSame(7990, $subscriptions->quoteForCompany($company));

        $invoice = $subscriptions->issueInvoice($company);
        $this->assertSame(7990, $invoice->amount_cents);
        $this->assertSame(7990, collect($invoice->items)->sum('price_cents'));

        $subscriptions->payInvoice($invoice);
        $this->assertSame(SubscriptionStatus::Active, $company->refresh()->subscription_status);
        $this->travelTo($company->current_period_end->copy()->subDay());
        $this->assertSame(1, $subscriptions->issueDueInvoices());
        $this->assertSame(7990, $company->platformInvoices()->latest('id')->firstOrFail()->amount_cents);
    }

    public function test_plan_only_applies_to_salon_companies(): void
    {
        $company = $this->createCompany([
            'business_profile' => CompanyProfile::TattooStudio,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
            'billing_interval' => BillingInterval::Monthly,
        ]);
        $company->forceFill(['subscription_plan' => BellanaLaunchPlan::KEY, 'subscription_price_cents' => 7990])->save();

        $this->assertSame(9980, app(CompanySubscriptionService::class)->quoteForCompany($company));
    }
}
