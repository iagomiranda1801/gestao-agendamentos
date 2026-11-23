<?php

namespace Tests\Feature\Landing;

use App\Enums\CompanyProfile;
use App\Livewire\Signup\CompanySignupWizard;
use Livewire\Livewire;
use Tests\TestCase;

class BellanaLandingTest extends TestCase
{
    public function test_bellana_page_links_to_bellana_signup_with_configured_price(): void
    {
        config(['services.bellana.whatsapp_url' => null, 'subscriptions.bellana_launch_price_cents' => 8990]);

        $this->get(route('bellana.landing'))
            ->assertOk()
            ->assertSee('Bellana')
            ->assertSee(route('bellana.signup'), false)
            ->assertSee('R$ 89,90')
            ->assertDontSee('R$ 79,90')
            ->assertDontSee('Falar no WhatsApp')
            ->assertDontSee('mailto:', false)
            ->assertDontSee('ilustrativ');
    }

    public function test_bellana_page_shows_whatsapp_button_when_configured(): void
    {
        config(['services.bellana.whatsapp_url' => 'https://wa.me/5585999999999']);

        $this->get(route('bellana.landing'))
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
