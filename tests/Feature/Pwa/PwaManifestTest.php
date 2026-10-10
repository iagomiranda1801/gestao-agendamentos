<?php

namespace Tests\Feature\Pwa;

use App\Enums\CompanyProfile;
use Tests\TestCase;

class PwaManifestTest extends TestCase
{
    public function test_tattoo_panel_manifest_uses_studio_brand_and_painel_start_url(): void
    {
        $this->get('http://estudio.localhost/manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Agendaqui Estúdio')
            ->assertJsonPath('start_url', '/painel')
            ->assertJsonPath('scope', '/painel')
            ->assertJsonPath('icons.0.src', '/images/estudio/icon-192.png');
    }

    public function test_main_panel_manifest_keeps_agendaqui_and_app_start_url(): void
    {
        $this->get(rtrim((string) config('app.url'), '/').'/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', 'Agendaqui')
            ->assertJsonPath('start_url', '/app')
            ->assertJsonPath('scope', '/app')
            ->assertJsonPath('icons.0.src', '/images/agendaqui/icon-192.png')
            ->assertJsonMissing(['name' => 'Agendaqui Estúdio']);
    }

    public function test_booking_manifest_uses_company_name(): void
    {
        $this->createCompany([
            'slug' => 'estudio-ink',
            'name' => 'Ink House',
            'business_profile' => CompanyProfile::TattooStudio,
        ]);

        $this->get('/agendar/estudio-ink/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', 'Ink House')
            ->assertJsonPath('start_url', '/agendar/estudio-ink')
            ->assertJsonPath('scope', '/agendar/estudio-ink')
            ->assertJsonPath('icons.0.src', '/images/estudio/icon-192.png');
    }

    public function test_orders_manifest_uses_company_path(): void
    {
        $this->createCompany([
            'slug' => 'cantina-ana',
            'name' => 'Cantina da Ana',
        ]);

        $this->get('/pedir/cantina-ana/manifest.webmanifest')
            ->assertOk()
            ->assertJsonPath('name', 'Cantina da Ana')
            ->assertJsonPath('start_url', '/pedir/cantina-ana');
    }

    public function test_offline_page_is_available(): void
    {
        $this->get('/offline')
            ->assertOk()
            ->assertSee('Sem conexão')
            ->assertSee('Agendaqui');
    }

    public function test_service_worker_is_served_with_root_scope_header(): void
    {
        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/')
            ->assertSee('agendaqui-pwa-v1', false)
            ->assertSee('/offline', false);
    }

    public function test_tattoo_login_registers_panel_manifest(): void
    {
        $this->withoutVite();

        $this->get('http://estudio.localhost/painel/login')
            ->assertOk()
            ->assertSee('/manifest.webmanifest', false)
            ->assertSee('/painel', false);
    }

    public function test_main_app_login_does_not_use_studio_manifest_name(): void
    {
        $this->withoutVite();

        $this->get(rtrim((string) config('app.url'), '/').'/app/login')
            ->assertOk()
            ->assertSee('/manifest.webmanifest', false)
            ->assertDontSee('Agendaqui Estúdio');
    }
}
