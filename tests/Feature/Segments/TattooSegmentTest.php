<?php

namespace Tests\Feature\Segments;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Filament\App\Pages\Dashboard;
use App\Livewire\Signup\CompanySignupWizard;
use App\Models\Company;
use App\Models\SegmentSetting;
use App\Services\WhatsApp\Automations\WhatsAppAutomationMessageBuilder;
use App\Support\Segment;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class TattooSegmentTest extends TestCase
{
    protected string $tattooHost = 'estudio.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_tattoo_login_shows_studio_brand_and_main_login_keeps_agendaqui(): void
    {
        config(['segments.tattoo.login.headline' => 'Headline do estúdio de teste']);

        $this->get("http://{$this->tattooHost}/painel/login")
            ->assertOk()
            ->assertSee('tattoo-auth', false)
            ->assertSee('Headline do estúdio de teste')
            ->assertSee('images/estudio/logo.svg', false);

        $this->get(rtrim((string) config('app.url'), '/').'/app/login')
            ->assertOk()
            ->assertSee('agendaqui-login-shell', false)
            ->assertDontSee('tattoo-auth', false)
            ->assertDontSee('salon-auth', false);
    }

    public function test_tattoo_domain_root_redirects_to_tattoo_panel(): void
    {
        $this->get("http://{$this->tattooHost}/")->assertRedirect("http://{$this->tattooHost}/painel");
    }

    public function test_admin_and_app_are_not_available_on_tattoo_domain(): void
    {
        $this->get("http://{$this->tattooHost}/admin/login")->assertNotFound();
        $this->get("http://{$this->tattooHost}/admin")->assertNotFound();
        $this->get("http://{$this->tattooHost}/app/login")->assertNotFound();
    }

    public function test_tattoo_company_can_open_tattoo_panel(): void
    {
        $company = $this->createCompany(['slug' => 'estudio-ink', 'business_profile' => CompanyProfile::TattooStudio]);
        $user = $this->createCompanyUser($company);

        $this->actingAs($user)
            ->get("http://{$this->tattooHost}/painel/empresa/estudio-ink")
            ->assertOk();
    }

    public function test_non_tattoo_company_cannot_open_tattoo_panel(): void
    {
        $company = $this->createCompany(['slug' => 'salao-bela', 'business_profile' => CompanyProfile::Salon]);
        $user = $this->createCompanyUser($company);

        $this->actingAs($user)
            ->get("http://{$this->tattooHost}/painel/empresa/salao-bela")
            ->assertNotFound();

        Filament::setCurrentPanel('estudio');

        $this->assertCount(0, $user->getTenants(Filament::getCurrentPanel()));
    }

    public function test_tattoo_company_on_main_panel_goes_to_tattoo_domain_when_enabled(): void
    {
        config(['segments.tattoo.enabled' => true, 'segments.tattoo.scheme' => 'http']);

        $company = $this->createCompany(['slug' => 'estudio-ink', 'business_profile' => CompanyProfile::TattooStudio]);
        $user = $this->createCompanyUser($company);

        $this->actingAs($user)
            ->get(Dashboard::getUrl(['tenant' => $company], panel: 'app'))
            ->assertRedirect("http://{$this->tattooHost}/painel/empresa/estudio-ink");
    }

    public function test_signup_on_tattoo_domain_creates_tattoo_company(): void
    {
        $this->get("http://{$this->tattooHost}/cadastro")
            ->assertOk()
            ->assertDontSee('id="businessProfile"', false);

        request()->headers->set('HOST', $this->tattooHost);

        Livewire::test(CompanySignupWizard::class)
            ->assertSet('businessProfile', CompanyProfile::TattooStudio->value)
            ->set('businessProfile', CompanyProfile::Salon->value)
            ->assertSet('businessProfile', CompanyProfile::TattooStudio->value)
            ->set('companyName', 'Estúdio Nova')
            ->set('companySlug', 'estudio-nova')
            ->call('goToModulesStep')
            ->set('selectedModules', [CompanyModule::Scheduling->value])
            ->call('goToAdminStep')
            ->set('adminName', 'Leo Admin')
            ->set('adminEmail', 'leo@estudio-nova.test')
            ->set('adminPassword', 'Password123!')
            ->set('adminPasswordConfirmation', 'Password123!')
            ->call('goToReviewStep')
            ->call('submit')
            ->assertRedirect("http://{$this->tattooHost}/painel/empresa/estudio-nova");

        $this->assertSame(CompanyProfile::TattooStudio, Company::query()->where('slug', 'estudio-nova')->firstOrFail()->business_profile);
    }

    public function test_public_links_use_tattoo_domain_only_for_tattoo_companies_when_enabled(): void
    {
        config(['segments.tattoo.enabled' => true, 'segments.tattoo.scheme' => 'https']);

        $tattoo = $this->createCompany(['slug' => 'estudio-ink', 'business_profile' => CompanyProfile::TattooStudio]);
        $salon = $this->createCompany(['slug' => 'salao-bela', 'business_profile' => CompanyProfile::Salon]);
        $builder = app(WhatsAppAutomationMessageBuilder::class);

        $this->assertSame("https://{$this->tattooHost}/agendar/estudio-ink", $builder->bookingUrl($tattoo));
        $this->assertSame(route('public.booking.show', ['company' => 'salao-bela']), $builder->bookingUrl($salon));
    }

    public function test_salon_without_saved_settings_keeps_config_brand(): void
    {
        SegmentSetting::query()->where('segment', 'salon')->delete();
        Segment::flush();

        $this->assertSame('Agendaqui Beleza', Segment::get('salon', 'name'));
    }
}
