<?php

namespace Tests\Feature\Landing;

use App\Enums\CompanyProfile;
use App\Livewire\Signup\CompanySignupWizard;
use Livewire\Livewire;
use Tests\TestCase;

class BellanaLandingTest extends TestCase
{
    protected string $salonHost = 'salao.localhost';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_salon_domain_root_shows_bellana_with_signup_and_login_on_the_same_domain(): void
    {
        config(['services.bellana.whatsapp_url' => null, 'subscriptions.bellana_launch_price_cents' => 8990]);

        $this->get("http://{$this->salonHost}/")
            ->assertOk()
            ->assertSee('Bellana')
            ->assertSee("http://{$this->salonHost}/cadastro", false)
            ->assertSee("http://{$this->salonHost}/painel/login", false)
            ->assertSee('R$ 89,90')
            ->assertDontSee('R$ 79,90')
            ->assertDontSee('Falar no WhatsApp')
            ->assertDontSee('mailto:', false)
            ->assertDontSee('ilustrativ');
    }

    public function test_salon_domain_signup_is_the_bellana_quick_signup(): void
    {
        $this->get("http://{$this->salonHost}/cadastro")
            ->assertOk()
            ->assertSee('Vamos criar sua conta?')
            ->assertSee("http://{$this->salonHost}/painel/login", false)
            ->assertDontSee('id="businessProfile"', false);
    }

    public function test_main_domain_keeps_admin_redirect_and_module_signup(): void
    {
        $main = rtrim((string) config('app.url'), '/');

        $this->get($main.'/')->assertRedirect($main.'/admin');
        $this->get($main.'/cadastro')
            ->assertOk()
            ->assertSee('Crie sua empresa')
            ->assertDontSee('Vamos criar sua conta?');
    }

    public function test_old_bellana_paths_redirect_permanently_to_the_salon_domain(): void
    {
        config(['segments.salon.enabled' => true, 'segments.salon.scheme' => 'https']);
        $main = rtrim((string) config('app.url'), '/');

        $this->get($main.'/bellana')
            ->assertStatus(301)
            ->assertRedirect("https://{$this->salonHost}");
        $this->get($main.'/bellana/cadastro')
            ->assertStatus(301)
            ->assertRedirect("https://{$this->salonHost}/cadastro");

        config(['segments.salon.enabled' => false]);
        $this->get($main.'/bellana')->assertNotFound();
    }

    public function test_bellana_page_shows_whatsapp_button_when_configured(): void
    {
        config(['services.bellana.whatsapp_url' => 'https://wa.me/5585999999999']);

        $this->get("http://{$this->salonHost}/")
            ->assertOk()
            ->assertSee('Falar no WhatsApp')
            ->assertSee('https://wa.me/5585999999999', false);
    }

    public function test_signup_preselects_profile_from_query(): void
    {
        Livewire::withQueryParams(['perfil' => CompanyProfile::Salon->value])
            ->test(CompanySignupWizard::class)
            ->assertSet('businessProfile', CompanyProfile::Salon->value);

        Livewire::withQueryParams(['perfil' => 'nao-existe'])
            ->test(CompanySignupWizard::class)
            ->assertSet('businessProfile', CompanyProfile::Professional->value);
    }
}
