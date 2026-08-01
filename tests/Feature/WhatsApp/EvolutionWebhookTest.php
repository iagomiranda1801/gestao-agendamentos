<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\WhatsAppCampaignAudience;
use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Models\Client;
use App\Models\CompanySchedulingSetting;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use Tests\TestCase;

class EvolutionWebhookTest extends TestCase
{
    public function test_webhook_requires_token_when_configured(): void
    {
        config(['services.evolution.webhook_token' => 'secret-token']);

        $this->postJson('/webhooks/evolution', [
            'event' => 'MESSAGES_UPDATE',
        ])->assertUnauthorized();
    }

    public function test_webhook_updates_campaign_recipient_status_by_provider_message_id(): void
    {
        config(['services.evolution.webhook_token' => 'secret-token']);

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
        $recipient->forceFill([
            'status' => WhatsAppCampaignRecipientStatus::Accepted,
            'provider_message_id' => 'provider-message-id',
            'provider_status' => 'PENDING',
        ])->save();

        $this->postJson('/webhooks/evolution?token=secret-token', [
            'event' => 'MESSAGES_UPDATE',
            'instance' => 'loja-1',
            'data' => [
                'key' => [
                    'id' => 'provider-message-id',
                    'remoteJid' => '5511999990001@s.whatsapp.net',
                ],
                'status' => 'DELIVERED',
            ],
        ])->assertOk()
            ->assertJson([
                'ok' => true,
                'processed' => true,
            ]);

        $recipient->refresh();
        $campaign->refresh();

        $this->assertSame(WhatsAppCampaignRecipientStatus::Delivered, $recipient->status);
        $this->assertSame('DELIVERED', $recipient->provider_status);
        $this->assertNotNull($recipient->sent_at);
        $this->assertSame(1, $campaign->sent_count);
        $this->assertDatabaseHas('evolution_webhook_events', [
            'event' => 'MESSAGES_UPDATE',
            'instance' => 'loja-1',
            'message_id' => 'provider-message-id',
            'provider_status' => 'DELIVERED',
        ]);
    }

    public function test_webhook_can_receive_instance_in_url(): void
    {
        config(['services.evolution.webhook_token' => 'secret-token']);

        $this->postJson('/webhooks/evolution/whatsapp-principal?token=secret-token', [
            'event' => 'SEND_MESSAGE',
            'data' => [
                'key' => ['id' => 'url-instance-message'],
                'status' => 'PENDING',
            ],
        ])->assertOk();

        $this->assertDatabaseHas('evolution_webhook_events', [
            'instance' => 'whatsapp-principal',
            'message_id' => 'url-instance-message',
        ]);
    }

    public function test_webhook_rejects_different_url_and_payload_instances(): void
    {
        config(['services.evolution.webhook_token' => 'secret-token']);

        $this->postJson('/webhooks/evolution/whatsapp-principal?token=secret-token', [
            'event' => 'SEND_MESSAGE',
            'instance' => 'another-instance',
        ])->assertUnprocessable();
    }

    public function test_webhook_updates_recipient_when_evolution_sends_array_update_with_numeric_status(): void
    {
        config(['services.evolution.webhook_token' => 'secret-token']);

        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        CompanySchedulingSetting::factory()->for($company)->create(['whatsapp_instance' => 'loja-1']);
        Client::factory()->forCompany($company)->optedInForWhatsAppMarketing()->create();
        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Campanha',
            'audience_type' => WhatsAppCampaignAudience::OptedInActiveClients->value,
            'message_template' => 'Mensagem',
        ]);
        app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->forceFill(['status' => WhatsAppCampaignRecipientStatus::Accepted, 'provider_message_id' => 'array-message'])->save();

        $this->postJson('/webhooks/evolution/loja-1?token=secret-token', [
            'event' => 'messages.update',
            'instance' => 'loja-1',
            'data' => [[
                'key' => ['id' => 'array-message'],
                'update' => ['status' => 3],
            ]],
        ])->assertOk()->assertJson(['processed' => true]);

        $this->assertSame(WhatsAppCampaignRecipientStatus::Delivered, $recipient->refresh()->status);
    }
}
