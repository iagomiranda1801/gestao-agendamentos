<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\CompanyModule;
use App\Enums\CompanyRole;
use App\Filament\App\Resources\WhatsAppInstances\WhatsAppInstanceResource;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppInstanceResourceTest extends TestCase
{
    public function test_instance_resource_requires_marketing_module(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);
        Filament::setCurrentPanel('app');

        $this->assertFalse(WhatsAppInstanceResource::canViewAny());

        $company->update([
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::Marketing->value],
        ]);

        $this->assertTrue(WhatsAppInstanceResource::canViewAny());
    }

    public function test_admin_can_render_instance_list_and_create_page(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Marketing->value],
        ]);
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);

        $this->authenticateForAppTenant($admin, $company);

        $this->get(WhatsAppInstanceResource::getUrl('index'))
            ->assertOk()
            ->assertSee('conexões WhatsApp');

        $this->get(WhatsAppInstanceResource::getUrl('create'))
            ->assertOk()
            ->assertSee('Criar Conexão WhatsApp');
    }

    public function test_default_instance_syncs_legacy_scheduling_settings(): void
    {
        $company = $this->createCompany();

        $instance = app(CompanyWhatsAppInstanceService::class)->create($company, [
            'name' => 'Principal',
            'instance_name' => 'estudio-ana',
            'sender_phone' => '(11) 99999-0001',
            'is_default' => true,
        ]);

        $setting = app(CompanySchedulingSettingService::class)->getOrCreate($company);

        $this->assertTrue($instance->is_default);
        $this->assertSame('estudio-ana', $setting->whatsapp_instance);
        $this->assertSame('11999990001', $setting->whatsapp_sender_phone);
    }

    public function test_generate_qr_updates_instance_and_legacy_settings(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
        ]);

        Http::fake([
            'https://evolution.test/instance/fetchInstances' => Http::response([]),
            'https://evolution.test/instance/create' => Http::response([
                'instance' => ['state' => 'qrcode'],
                'qrcode' => ['base64' => 'data:image/png;base64,abc'],
            ]),
        ]);

        $company = $this->createCompany();
        $instance = app(CompanyWhatsAppInstanceService::class)->create($company, [
            'name' => 'Principal',
            'instance_name' => 'estudio-ana',
            'sender_phone' => '(11) 99999-0001',
            'is_default' => true,
        ]);

        app(CompanyWhatsAppInstanceService::class)->createOrRefreshQrCode($company, $instance);

        $instance->refresh();
        $setting = app(CompanySchedulingSettingService::class)->getOrCreate($company);

        $this->assertSame('qrcode', $instance->status);
        $this->assertSame('data:image/png;base64,abc', $instance->qr_code);
        $this->assertSame('data:image/png;base64,abc', $setting->whatsapp_instance_qr_code);
    }

    public function test_existing_evolution_instance_is_reused_by_phone_without_creating_another_one(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
        ]);

        Http::fake([
            'https://evolution.test/instance/fetchInstances' => Http::response([
                [
                    'name' => 'whatsapp-estudio-ana',
                    'connectionStatus' => 'open',
                    'ownerJid' => '553499206651@s.whatsapp.net',
                ],
            ]),
            'https://evolution.test/instance/create' => Http::response([], 500),
        ]);

        $company = $this->createCompany();
        $instance = app(CompanyWhatsAppInstanceService::class)->create($company, [
            'name' => 'Principal',
            'instance_name' => 'nova-conexao',
            'sender_phone' => '(34) 9992-06651',
            'is_default' => true,
        ]);

        $instance = app(CompanyWhatsAppInstanceService::class)->createOrRefreshQrCode($company, $instance);

        $this->assertSame('whatsapp-estudio-ana', $instance->instance_name);
        $this->assertSame('open', $instance->status);
        $this->assertNotNull($instance->connected_at);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/instance/create'));
    }

    public function test_closed_existing_instance_requests_a_new_qr_without_creating_another_one(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
        ]);

        Http::fake([
            'https://evolution.test/instance/fetchInstances' => Http::response([
                [
                    'name' => 'whatsapp-estudio-ana',
                    'connectionStatus' => 'close',
                    'ownerJid' => '553499206651@s.whatsapp.net',
                ],
            ]),
            'https://evolution.test/instance/connect/whatsapp-estudio-ana' => Http::response([
                'instance' => ['state' => 'qrcode'],
                'qrcode' => ['base64' => 'data:image/png;base64,reconnect'],
            ]),
        ]);

        $company = $this->createCompany();
        $instance = app(CompanyWhatsAppInstanceService::class)->create($company, [
            'name' => 'Principal',
            'instance_name' => 'nova-conexao',
            'sender_phone' => '(34) 9992-06651',
            'is_default' => true,
        ]);

        $instance = app(CompanyWhatsAppInstanceService::class)->createOrRefreshQrCode($company, $instance);

        $this->assertSame('qrcode', $instance->status);
        $this->assertSame('data:image/png;base64,reconnect', $instance->qr_code);
        Http::assertNotSent(fn ($request): bool => str_ends_with($request->url(), '/instance/create'));
    }
}
