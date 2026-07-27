<?php

namespace Tests\Feature\Filament;

use Tests\TestCase;

class AppLoginSignupLinkTest extends TestCase
{
    public function test_app_login_page_shows_signup_link(): void
    {
        $this->get('/app/login')
            ->assertOk()
            ->assertSee('Criar conta — 7 dias grátis', false)
            ->assertSee(route('signup.company'), false);
    }

    public function test_admin_login_page_does_not_show_signup_link(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertDontSee('Criar conta — 7 dias grátis', false);
    }
}
