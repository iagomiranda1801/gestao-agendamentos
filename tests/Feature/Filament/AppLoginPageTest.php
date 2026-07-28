<?php

namespace Tests\Feature\Filament;

use App\Filament\App\Pages\Auth\Login;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

class AppLoginPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('app');
    }

    public function test_app_login_page_is_accessible_for_guests(): void
    {
        $this->get('/app/login')
            ->assertOk();
    }

    public function test_app_login_page_shows_agendaqui_branding(): void
    {
        $this->get('/app/login')
            ->assertOk()
            ->assertSee('Agendaqui', false)
            ->assertSee('images/aqui.png', false)
            ->assertSee('Bem-vindo de volta', false)
            ->assertSee('Gestão inteligente', false);
    }

    public function test_app_login_page_has_email_password_and_submit(): void
    {
        $this->get('/app/login')
            ->assertOk()
            ->assertSee('E-mail', false)
            ->assertSee('Senha', false)
            ->assertSee('Lembrar-me', false)
            ->assertSee('Entrar', false)
            ->assertSee('seu@email.com', false);
    }

    public function test_app_login_page_shows_security_note(): void
    {
        $this->get('/app/login')
            ->assertOk()
            ->assertSee('Plataforma segura', false);
    }

    public function test_app_login_page_does_not_show_password_reset_when_disabled(): void
    {
        $this->get('/app/login')
            ->assertOk()
            ->assertDontSee('Esqueceu', false);
    }

    public function test_valid_login_authenticates_company_user(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company, [
            'email' => 'admin@empresa.test',
            'password' => 'password',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'admin@empresa.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_login_shows_validation_error(): void
    {
        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'invalid@example.test',
                'password' => 'wrong-password',
            ])
            ->call('authenticate')
            ->assertHasErrors(['data.email']);
    }

    public function test_user_without_panel_access_cannot_login(): void
    {
        User::factory()->create([
            'email' => 'sem-acesso@example.test',
            'password' => 'password',
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'sem-acesso@example.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $company = $this->createCompany();
        $this->createCompanyUser($company, [
            'email' => 'inativo@empresa.test',
            'password' => 'password',
            'is_active' => false,
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => 'inativo@empresa.test',
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest();
    }

    public function test_app_login_uses_custom_login_class(): void
    {
        $this->assertSame(
            Login::class,
            Filament::getPanel('app')->getLoginRouteAction()
        );
    }

    public function test_admin_login_remains_accessible(): void
    {
        $this->get('/admin/login')
            ->assertOk()
            ->assertSee('images/aqui.png', false);
    }
}
