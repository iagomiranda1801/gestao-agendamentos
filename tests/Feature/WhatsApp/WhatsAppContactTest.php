<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\WhatsAppContactService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppContactTest extends TestCase
{
    public function test_contacts_sync_is_idempotent_and_links_existing_clients(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
        ]);

        Http::fake([
            'https://evolution.test/chat/findContacts/estudio-ana' => Http::response([
                [
                    'id' => '5511999990001@s.whatsapp.net',
                    'pushName' => 'Cliente existente',
                    'number' => '5511999990001',
                ],
                [
                    'id' => '553499206651@s.whatsapp.net',
                    'pushName' => 'Novo contato',
                    'remoteJid' => '553499206651@s.whatsapp.net',
                ],
                [
                    'id' => '120363000000000000@g.us',
                    'pushName' => 'Grupo ignorado',
                    'remoteJid' => '120363000000000000@g.us',
                    'isGroup' => true,
                ],
            ]),
        ]);

        $company = $this->createCompany();
        $client = Client::factory()->forCompany($company)->create([
            'name' => 'Cliente cadastrado',
            'phone' => '11999990001',
        ]);
        $instance = app(CompanyWhatsAppInstanceService::class)->create($company, [
            'name' => 'Principal',
            'instance_name' => 'estudio-ana',
            'status' => 'open',
            'is_default' => true,
        ]);

        $service = app(WhatsAppContactService::class);

        $this->assertSame(2, $service->sync($company, $instance));
        $this->assertSame(2, $service->sync($company, $instance));
        $this->assertDatabaseCount('whatsapp_contacts', 2);

        $this->assertDatabaseHas('whatsapp_contacts', [
            'phone_normalized' => '5511999990001',
            'client_id' => $client->getKey(),
        ]);
    }

    public function test_import_creates_client_without_marketing_consent(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
        ]);

        Http::fake([
            'https://evolution.test/chat/findContacts/estudio-ana' => Http::response([
                [
                    'id' => '553499206651@s.whatsapp.net',
                    'pushName' => 'Novo contato',
                    'number' => '553499206651',
                ],
            ]),
        ]);

        $company = $this->createCompany();
        $instance = app(CompanyWhatsAppInstanceService::class)->create($company, [
            'name' => 'Principal',
            'instance_name' => 'estudio-ana',
            'status' => 'open',
            'is_default' => true,
        ]);
        $service = app(WhatsAppContactService::class);
        $service->sync($company, $instance);

        $contact = $company->whatsappContacts()->firstOrFail();
        $result = $service->importAsClients($company, new \Illuminate\Database\Eloquent\Collection([$contact]));

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('clients', [
            'company_id' => $company->getKey(),
            'phone_normalized' => '553499206651',
            'whatsapp_marketing_opt_in' => false,
        ]);
        $this->assertNotNull($contact->refresh()->imported_as_client_at);
    }
}
