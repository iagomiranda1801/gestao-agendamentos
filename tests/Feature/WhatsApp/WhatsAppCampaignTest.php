<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\CompanyModule;
use App\Enums\WhatsAppCampaignAudience;
use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Enums\WhatsAppCampaignStatus;
use App\Filament\App\Resources\WhatsAppCampaigns\WhatsAppCampaignResource;
use App\Jobs\SendWhatsAppCampaignRecipientJob;
use App\Models\Client;
use App\Models\CompanySchedulingSetting;
use App\Models\WhatsAppCampaign;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use App\Services\WhatsApp\EvolutionApiClient;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WhatsAppCampaignTest extends TestCase
{
    public function test_campaign_resource_requires_marketing_module(): void
    {
        $company = $this->createCompany([
            'enabled_modules' => [CompanyModule::Scheduling->value],
        ]);
        $admin = $this->createCompanyUser($company);

        $this->authenticateForAppTenant($admin, $company);
        Filament::setCurrentPanel('app');

        $this->assertFalse(WhatsAppCampaignResource::canViewAny());

        $company->update([
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::Marketing->value],
        ]);

        $this->assertTrue(WhatsAppCampaignResource::canViewAny());
    }

    public function test_prepare_recipients_uses_only_active_clients_with_whatsapp_marketing_opt_in(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);

        $allowed = Client::factory()
            ->forCompany($company)
            ->optedInForWhatsAppMarketing()
            ->create([
                'name' => 'Ana Cliente',
                'phone' => '(11) 99999-0001',
            ]);
        Client::factory()
            ->forCompany($company)
            ->create(['phone' => '(11) 99999-0002']);
        Client::factory()
            ->forCompany($company)
            ->optedInForWhatsAppMarketing()
            ->inactive()
            ->create(['phone' => '(11) 99999-0003']);

        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Promo julho',
            'audience_type' => WhatsAppCampaignAudience::OptedInActiveClients->value,
            'message_template' => 'Oi {nome}, aqui é {empresa}.',
            'send_interval_seconds' => 20,
        ]);

        $count = app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('whatsapp_campaign_recipients', [
            'whatsapp_campaign_id' => $campaign->getKey(),
            'client_id' => $allowed->getKey(),
            'phone' => '11999990001',
            'message_snapshot' => "Oi Ana Cliente, aqui é {$company->name}.",
        ]);
    }

    public function test_prepare_recipients_can_use_selected_clients(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);

        $selectedA = Client::factory()
            ->forCompany($company)
            ->create([
                'name' => 'Cliente Um',
                'phone' => '(11) 99999-1001',
            ]);
        $selectedB = Client::factory()
            ->forCompany($company)
            ->create([
                'name' => 'Cliente Dois',
                'phone' => '(11) 99999-1002',
            ]);
        $notSelected = Client::factory()
            ->forCompany($company)
            ->optedInForWhatsAppMarketing()
            ->create(['phone' => '(11) 99999-1003']);

        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Manual',
            'audience_type' => WhatsAppCampaignAudience::SelectedClients->value,
            'selected_client_ids' => [$selectedA->getKey(), $selectedB->getKey()],
            'message_template' => 'Oi {nome}',
            'send_interval_seconds' => 20,
        ]);

        $count = app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('whatsapp_campaign_recipients', [
            'whatsapp_campaign_id' => $campaign->getKey(),
            'client_id' => $selectedA->getKey(),
        ]);
        $this->assertDatabaseHas('whatsapp_campaign_recipients', [
            'whatsapp_campaign_id' => $campaign->getKey(),
            'client_id' => $selectedB->getKey(),
        ]);
        $this->assertDatabaseMissing('whatsapp_campaign_recipients', [
            'whatsapp_campaign_id' => $campaign->getKey(),
            'client_id' => $notSelected->getKey(),
        ]);
    }

    public function test_start_sending_queues_prepared_recipients_on_default_queue(): void
    {
        Queue::fake();

        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        Client::factory()
            ->forCompany($company)
            ->optedInForWhatsAppMarketing()
            ->create(['phone' => '(11) 99999-0001']);

        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Campanha',
            'audience_type' => WhatsAppCampaignAudience::OptedInActiveClients->value,
            'message_template' => 'Mensagem',
            'send_interval_seconds' => 10,
        ]);
        app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);

        app(WhatsAppCampaignService::class)->startSending($company, $campaign->refresh());

        Queue::assertPushed(SendWhatsAppCampaignRecipientJob::class);
        $this->assertSame(WhatsAppCampaignStatus::Sending, $campaign->refresh()->status);
        $this->assertDatabaseHas('whatsapp_campaign_recipients', [
            'whatsapp_campaign_id' => $campaign->getKey(),
            'status' => WhatsAppCampaignRecipientStatus::Queued->value,
        ]);
    }

    public function test_campaign_job_sends_message_and_marks_recipient_sent(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
        ]);

        Http::fake([
            'https://evolution.test/message/sendText/loja-1' => Http::response(['ok' => true]),
        ]);

        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        CompanySchedulingSetting::factory()->for($company)->create([
            'whatsapp_instance' => 'loja-1',
        ]);
        Client::factory()
            ->forCompany($company)
            ->optedInForWhatsAppMarketing()
            ->create(['phone' => '(11) 99999-0001']);

        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Campanha',
            'audience_type' => WhatsAppCampaignAudience::OptedInActiveClients->value,
            'message_template' => 'Mensagem',
            'send_interval_seconds' => 10,
        ]);
        app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->forceFill(['status' => WhatsAppCampaignRecipientStatus::Queued])->save();
        $campaign->forceFill(['status' => WhatsAppCampaignStatus::Sending])->save();

        (new SendWhatsAppCampaignRecipientJob($recipient->getKey()))->handle(
            app(EvolutionApiClient::class),
            app(\App\Services\Scheduling\CompanySchedulingSettingService::class),
            app(\App\Services\WhatsApp\CompanyWhatsAppInstanceService::class),
            app(WhatsAppCampaignService::class),
        );

        $this->assertSame(WhatsAppCampaignRecipientStatus::Sent, $recipient->refresh()->status);
        $this->assertSame(WhatsAppCampaignStatus::Completed, $campaign->refresh()->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://evolution.test/message/sendText/loja-1'
            && $request['number'] === '5511999990001');
    }

    public function test_evolution_client_can_create_and_check_instance(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
        ]);

        Http::fake([
            'https://evolution.test/instance/create' => Http::response([
                'instance' => ['instanceName' => 'empresa-1'],
                'qrcode' => ['base64' => 'data:image/png;base64,abc'],
            ]),
            'https://evolution.test/instance/connectionState/empresa-1' => Http::response([
                'instance' => ['state' => 'open'],
            ]),
        ]);

        $client = app(EvolutionApiClient::class);

        $create = $client->createInstance('empresa-1', 'token', true);
        $state = $client->connectionState('empresa-1');

        $this->assertSame('data:image/png;base64,abc', $create['qrcode']['base64']);
        $this->assertSame('open', $state['instance']['state']);
    }
}
