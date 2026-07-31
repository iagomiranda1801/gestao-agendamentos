<?php

namespace Tests\Feature\WhatsApp;

use App\Models\Client;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\WhatsAppContactCleanupService;
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

    public function test_cleanup_deletes_only_contacts_matching_filters(): void
    {
        $company = $this->createCompany();
        $otherCompany = $this->createCompany();
        $instance = app(CompanyWhatsAppInstanceService::class)->create($company, [
            'name' => 'Principal',
            'instance_name' => 'principal',
        ]);
        $otherInstance = app(CompanyWhatsAppInstanceService::class)->create($company, [
            'name' => 'Secundária',
            'instance_name' => 'secundaria',
        ]);
        $foreignInstance = app(CompanyWhatsAppInstanceService::class)->create($otherCompany, [
            'name' => 'Outra',
            'instance_name' => 'outra',
        ]);
        $client = Client::factory()->forCompany($company)->create();

        $deleteMe = WhatsAppContact::query()->create([
            'company_id' => $company->getKey(),
            'company_whatsapp_instance_id' => $instance->getKey(),
            'external_id' => 'delete-me',
            'name' => 'Contato antigo',
            'phone' => '34999990001',
            'phone_normalized' => '34999990001',
            'last_synced_at' => now()->subDays(10),
            'imported_as_client_at' => null,
        ]);
        $imported = WhatsAppContact::query()->create([
            'company_id' => $company->getKey(),
            'company_whatsapp_instance_id' => $instance->getKey(),
            'client_id' => $client->getKey(),
            'external_id' => 'imported',
            'name' => 'Contato importado',
            'phone' => '34999990002',
            'phone_normalized' => '34999990002',
            'last_synced_at' => now()->subDays(10),
            'imported_as_client_at' => now(),
        ]);
        $otherInstanceContact = WhatsAppContact::query()->create([
            'company_id' => $company->getKey(),
            'company_whatsapp_instance_id' => $otherInstance->getKey(),
            'external_id' => 'other-instance',
            'name' => 'Outra instância',
            'phone' => '34999990003',
            'phone_normalized' => '34999990003',
            'last_synced_at' => now()->subDays(10),
        ]);
        $foreignContact = WhatsAppContact::query()->create([
            'company_id' => $otherCompany->getKey(),
            'company_whatsapp_instance_id' => $foreignInstance->getKey(),
            'external_id' => 'foreign',
            'name' => 'Outra empresa',
            'phone' => '34999990004',
            'phone_normalized' => '34999990004',
            'last_synced_at' => now()->subDays(10),
        ]);

        $deleted = app(WhatsAppContactCleanupService::class)->deleteByFilters($company, [
            'instance_id' => $instance->getKey(),
            'import_status' => 'not_imported',
            'synced_before' => now()->subDay()->toDateString(),
        ]);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('whatsapp_contacts', ['id' => $deleteMe->getKey()]);
        $this->assertDatabaseHas('whatsapp_contacts', ['id' => $imported->getKey()]);
        $this->assertDatabaseHas('whatsapp_contacts', ['id' => $otherInstanceContact->getKey()]);
        $this->assertDatabaseHas('whatsapp_contacts', ['id' => $foreignContact->getKey()]);
        $this->assertDatabaseHas('clients', ['id' => $client->getKey()]);
    }
}
