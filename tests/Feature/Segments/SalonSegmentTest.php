<?php

namespace Tests\Feature\Segments;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Filament\App\Pages\Dashboard;
use App\Livewire\Signup\CompanySignupWizard;
use App\Models\Company;
use App\Services\WhatsApp\Automations\WhatsAppAutomationMessageBuilder;
use App\Support\Segment;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class SalonSegmentTest extends TestCase
{
    protected string $salonHost = 'salao.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_salon_login_shows_salon_brand_and_main_login_keeps_agendaqui(): void
    {
        config(['segments.salon.login.headline' => 'Headline do salão de teste']);

        $this->get("http://{$this->salonHost}/painel/login")
            ->assertOk()
            ->assertSee('salon-auth', false)
            ->assertSee('Headline do salão de teste')
            ->assertSee('images/salao/logo.svg', false);

        $this->get(rtrim((string) config('app.url'), '/').'/app/login')
            ->assertOk()
            ->assertSee('agendaqui-login-shell', false)
            ->assertDontSee('salon-auth', false);
    }

    public function test_salon_domain_root_redirects_to_salon_panel(): void
    {
        $this->get("http://{$this->salonHost}/")->assertRedirect("http://{$this->salonHost}/painel");
    }

    public function test_admin_and_app_are_not_available_on_salon_domain(): void
    {
        $this->get("http://{$this->salonHost}/admin/login")->assertNotFound();
        $this->get("http://{$this->salonHost}/admin")->assertNotFound();
        $this->get("http://{$this->salonHost}/app/login")->assertNotFound();
    }

    public function test_salon_company_can_open_salon_panel(): void
    {
        $company = $this->createCompany(['slug' => 'salao-bela', 'business_profile' => CompanyProfile::Salon]);
        $user = $this->createCompanyUser($company);

        $this->actingAs($user)
            ->get("http://{$this->salonHost}/painel/empresa/salao-bela")
            ->assertOk();
    }

    public function test_non_salon_company_cannot_open_salon_panel(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-tattoo', 'business_profile' => CompanyProfile::TattooStudio]);
        $user = $this->createCompanyUser($company);

        $this->actingAs($user)
            ->get("http://{$this->salonHost}/painel/empresa/estudio-tattoo")
            ->assertNotFound();

        Filament::setCurrentPanel('salao');

        $this->assertCount(0, $user->getTenants(Filament::getCurrentPanel()));
    }

    public function test_salon_company_on_main_panel_goes_to_salon_domain_when_enabled(): void
    {
        config(['segments.salon.enabled' => true, 'segments.salon.scheme' => 'http']);

        $company = $this->createCompany(['slug' => 'salao-bela', 'business_profile' => CompanyProfile::Salon]);
        $user = $this->createCompanyUser($company);

        $this->actingAs($user)
            ->get(Dashboard::getUrl(['tenant' => $company], panel: 'app'))
            ->assertRedirect("http://{$this->salonHost}/painel/empresa/salao-bela");
    }

    public function test_salon_company_stays_on_main_panel_while_segment_is_disabled(): void
    {
        config(['segments.salon.enabled' => false]);

        $company = $this->createCompany(['slug' => 'salao-bela', 'business_profile' => CompanyProfile::Salon]);
        $user = $this->createCompanyUser($company);

        $this->actingAs($user)
            ->get(Dashboard::getUrl(['tenant' => $company], panel: 'app'))
            ->assertOk();
    }

    public function test_signup_on_salon_domain_creates_salon_company(): void
    {
        $this->get("http://{$this->salonHost}/cadastro")
            ->assertOk()
            ->assertDontSee('id="businessProfile"', false);

        request()->headers->set('HOST', $this->salonHost);

        Livewire::test(CompanySignupWizard::class)
            ->assertSet('businessProfile', CompanyProfile::Salon->value)
            ->set('businessProfile', CompanyProfile::TattooStudio->value)
            ->assertSet('businessProfile', CompanyProfile::Salon->value)
            ->set('companyName', 'Salão Nova')
            ->set('companySlug', 'salao-nova')
            ->call('goToModulesStep')
            ->set('selectedModules', [CompanyModule::Scheduling->value])
            ->call('goToAdminStep')
            ->set('adminName', 'Maria Admin')
            ->set('adminEmail', 'maria@salao-nova.test')
            ->set('adminPassword', 'Password123!')
            ->set('adminPasswordConfirmation', 'Password123!')
            ->call('goToReviewStep')
            ->call('submit')
            ->assertRedirect("http://{$this->salonHost}/painel/empresa/salao-nova");

        $this->assertSame(CompanyProfile::Salon, Company::query()->where('slug', 'salao-nova')->firstOrFail()->business_profile);
    }

    public function test_public_links_use_salon_domain_only_for_salon_companies_when_enabled(): void
    {
        config(['segments.salon.enabled' => true, 'segments.salon.scheme' => 'https']);

        $salon = $this->createCompany(['slug' => 'salao-bela', 'business_profile' => CompanyProfile::Salon]);
        $tattoo = $this->createCompany(['slug' => 'estudio-tattoo', 'business_profile' => CompanyProfile::TattooStudio]);
        $builder = app(WhatsAppAutomationMessageBuilder::class);

        $this->assertSame("https://{$this->salonHost}/agendar/salao-bela", $builder->bookingUrl($salon));
        $this->assertSame(route('public.booking.show', ['company' => 'estudio-tattoo']), $builder->bookingUrl($tattoo));

        config(['segments.salon.enabled' => false]);

        $this->assertSame(route('public.booking.show', ['company' => 'salao-bela']), Segment::route($salon, 'public.booking.show', ['company' => 'salao-bela']));
    }
}
