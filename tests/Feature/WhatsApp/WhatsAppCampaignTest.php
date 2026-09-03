<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\CompanyModule;
use App\Enums\WhatsAppCampaignAudience;
use App\Enums\WhatsAppCampaignRecipientStatus;
use App\Enums\WhatsAppCampaignStatus;
use App\Filament\App\Resources\WhatsAppCampaigns\WhatsAppCampaignResource;
use App\Jobs\SendWhatsAppCampaignEmailJob;
use App\Jobs\SendWhatsAppCampaignRecipientJob;
use App\Jobs\StartScheduledWhatsAppCampaignJob;
use App\Models\Client;
use App\Models\CompanySchedulingSetting;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundGate;
use Filament\Facades\Filament;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
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
            ->optedInForWhatsAppMarketing()
            ->create([
                'name' => 'Cliente Um',
                'phone' => '(11) 99999-1001',
            ]);
        $selectedB = Client::factory()
            ->forCompany($company)
            ->optedInForWhatsAppMarketing()
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
            ->create(['phone' => '(11) 99999-0001', 'email' => 'cliente@example.com']);

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

        Queue::fake();

        (new SendWhatsAppCampaignRecipientJob($recipient->getKey()))->handle(
            app(EvolutionApiClient::class),
            app(CompanySchedulingSettingService::class),
            app(CompanyWhatsAppInstanceService::class),
            app(WhatsAppCampaignService::class),
        );

        $this->assertSame(WhatsAppCampaignRecipientStatus::Sent, $recipient->refresh()->status);
        $this->assertSame(WhatsAppCampaignStatus::Completed, $campaign->refresh()->status);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://evolution.test/message/sendText/loja-1'
            && $request['number'] === '5511999990001');
        Queue::assertPushed(SendWhatsAppCampaignEmailJob::class, 1);
    }

    public function test_campaign_can_be_scheduled_after_preparing_its_recipients(): void
    {
        Queue::fake();

        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        Client::factory()
            ->forCompany($company)
            ->optedInForWhatsAppMarketing()
            ->create(['phone' => '(11) 99999-0001']);

        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Campanha agendada',
            'audience_type' => WhatsAppCampaignAudience::OptedInActiveClients->value,
            'message_template' => 'Mensagem',
            'send_interval_seconds' => 10,
        ]);
        app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);

        $scheduled = app(WhatsAppCampaignService::class)->schedule($company, $campaign, now()->addHour());

        $this->assertSame(WhatsAppCampaignStatus::Scheduled, $scheduled->status);
        $this->assertNotNull($scheduled->scheduled_at);
        Queue::assertPushed(StartScheduledWhatsAppCampaignJob::class);
    }

    public function test_campaign_job_marks_pending_provider_response_as_accepted_not_sent(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
        ]);

        Http::fake([
            'https://evolution.test/message/sendText/loja-1' => Http::response([
                'key' => ['id' => 'provider-message-id'],
                'status' => 'PENDING',
            ], 201),
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
            app(CompanySchedulingSettingService::class),
            app(CompanyWhatsAppInstanceService::class),
            app(WhatsAppCampaignService::class),
        );

        $recipient->refresh();
        $campaign->refresh();

        $this->assertSame(WhatsAppCampaignRecipientStatus::Accepted, $recipient->status);
        $this->assertNull($recipient->sent_at);
        $this->assertSame('PENDING', $recipient->provider_status);
        $this->assertSame('provider-message-id', $recipient->provider_message_id);
        $this->assertSame(0, $campaign->sent_count);
        $this->assertSame(1, $campaign->accepted_count);
        $this->assertSame(WhatsAppCampaignStatus::Completed, $campaign->status);
    }

    public function test_campaign_job_skips_client_that_withdrew_marketing_consent(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $client = Client::factory()
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
        $client->forceFill(['whatsapp_marketing_opt_in' => false])->save();

        (new SendWhatsAppCampaignRecipientJob($recipient->getKey()))->handle(
            app(EvolutionApiClient::class),
            app(CompanySchedulingSettingService::class),
            app(CompanyWhatsAppInstanceService::class),
            app(WhatsAppCampaignService::class),
        );

        $this->assertSame(WhatsAppCampaignRecipientStatus::Skipped, $recipient->refresh()->status);
        $this->assertSame(WhatsAppCampaignStatus::Completed, $campaign->refresh()->status);
    }

    public function test_completed_campaign_can_be_copied_to_edit_again(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);

        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Campanha original',
            'audience_type' => WhatsAppCampaignAudience::SelectedClients->value,
            'selected_client_ids' => [10, 20],
            'message_template' => 'Mensagem original',
            'send_interval_seconds' => 30,
        ]);
        $campaign->forceFill(['status' => WhatsAppCampaignStatus::Completed])->save();

        $copy = app(WhatsAppCampaignService::class)->duplicateForResend($company, $campaign, $user);

        $this->assertSame('Campanha original - reenvio', $copy->name);
        $this->assertSame(WhatsAppCampaignStatus::Draft, $copy->status);
        $this->assertSame(WhatsAppCampaignAudience::SelectedClients, $copy->audience_type);
        $this->assertSame([10, 20], $copy->selected_client_ids);
        $this->assertSame('Mensagem original', $copy->message_template);
        $this->assertSame(0, $copy->total_recipients);
    }

    public function test_completed_campaign_can_be_resent_as_new_campaign(): void
    {
        Queue::fake();

        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $client = Client::factory()
            ->forCompany($company)
            ->optedInForWhatsAppMarketing()
            ->create(['phone' => '(11) 99999-0001']);

        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Campanha original',
            'audience_type' => WhatsAppCampaignAudience::SelectedClients->value,
            'selected_client_ids' => [$client->getKey()],
            'message_template' => 'Mensagem original',
            'send_interval_seconds' => 10,
        ]);
        $campaign->forceFill(['status' => WhatsAppCampaignStatus::Completed])->save();

        $resent = app(WhatsAppCampaignService::class)->resend($company, $campaign, $user);

        $this->assertNotSame($campaign->getKey(), $resent->getKey());
        $this->assertSame(WhatsAppCampaignStatus::Sending, $resent->status);
        $this->assertSame(1, $resent->total_recipients);
        Queue::assertPushed(SendWhatsAppCampaignRecipientJob::class);
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

    public function test_campaign_job_sends_image_with_caption_when_campaign_has_image(): void
    {
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
            'filesystems.company_logo_disk' => 's3',
        ]);

        Storage::fake('s3');
        $path = 'agendaqui/empresa/campanhas/promo.jpg';
        Storage::disk('s3')->put($path, $this->tinyJpeg());

        Http::fake([
            'https://evolution.test/message/sendMedia/loja-1' => Http::response(['ok' => true]),
        ]);

        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        CompanySchedulingSetting::factory()->for($company)->create([
            'whatsapp_instance' => 'loja-1',
        ]);
        Client::factory()
            ->forCompany($company)
            ->optedInForWhatsAppMarketing()
            ->create(['name' => 'Ana Cliente', 'phone' => '(11) 99999-0001']);

        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Promo com foto',
            'audience_type' => WhatsAppCampaignAudience::OptedInActiveClients->value,
            'message_template' => 'Oi {nome}, aqui é {empresa}.',
            'image_path' => $path,
            'image_disk' => 's3',
            'send_interval_seconds' => 40,
        ]);

        $this->assertSame('image/jpeg', $campaign->image_mime);

        app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->forceFill(['status' => WhatsAppCampaignRecipientStatus::Queued])->save();
        $campaign->forceFill(['status' => WhatsAppCampaignStatus::Sending])->save();

        (new SendWhatsAppCampaignRecipientJob($recipient->getKey()))->handle(
            app(EvolutionApiClient::class),
            app(CompanySchedulingSettingService::class),
            app(CompanyWhatsAppInstanceService::class),
            app(WhatsAppCampaignService::class),
        );

        $this->assertSame(WhatsAppCampaignRecipientStatus::Sent, $recipient->refresh()->status);
        Http::assertSent(function ($request) use ($company): bool {
            return $request->url() === 'https://evolution.test/message/sendMedia/loja-1'
                && ($request['number'] ?? null) === '5511999990001'
                && ($request['mediatype'] ?? null) === 'image'
                && ($request['mimetype'] ?? null) === 'image/jpeg'
                && ($request['caption'] ?? null) === "Oi Ana Cliente, aqui é {$company->name}."
                && filled($request['media'] ?? null);
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/message/sendText/'));
    }

    public function test_campaign_rejects_non_image_attachment(): void
    {
        config(['filesystems.company_logo_disk' => 's3']);
        Storage::fake('s3');
        $path = 'agendaqui/empresa/campanhas/arquivo.txt';
        Storage::disk('s3')->put($path, 'nao e imagem');

        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);

        $this->expectException(ValidationException::class);

        app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Inválida',
            'audience_type' => WhatsAppCampaignAudience::OptedInActiveClients->value,
            'message_template' => 'Oi',
            'image_path' => $path,
            'image_disk' => 's3',
            'send_interval_seconds' => 40,
        ]);
    }

    public function test_campaign_duplicate_copies_image(): void
    {
        config(['filesystems.company_logo_disk' => 's3']);
        Storage::fake('s3');
        $path = 'agendaqui/empresa/campanhas/promo.png';
        Storage::disk('s3')->put($path, $this->tinyPng());

        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);

        $campaign = app(WhatsAppCampaignService::class)->create($company, $user, [
            'name' => 'Original',
            'audience_type' => WhatsAppCampaignAudience::OptedInActiveClients->value,
            'message_template' => 'Oi',
            'image_path' => $path,
            'image_disk' => 's3',
            'send_interval_seconds' => 40,
        ]);

        $copy = app(WhatsAppCampaignService::class)->duplicateForResend($company, $campaign, $user);

        $this->assertSame($campaign->image_path, $copy->image_path);
        $this->assertSame($campaign->image_disk, $copy->image_disk);
        $this->assertSame($campaign->image_mime, $copy->image_mime);
        $this->assertSame('image/png', $copy->image_mime);
    }

    public function test_campaign_job_releases_when_recipient_is_still_pending_during_send(): void
    {
        Http::fake();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

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
            'send_interval_seconds' => 30,
        ]);
        app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $campaign->forceFill(['status' => WhatsAppCampaignStatus::Sending])->save();

        $this->assertSame(WhatsAppCampaignRecipientStatus::Pending, $recipient->status);

        (new SendWhatsAppCampaignRecipientJob($recipient->getKey()))->handle(
            app(EvolutionApiClient::class),
            app(CompanySchedulingSettingService::class),
            app(CompanyWhatsAppInstanceService::class),
            app(WhatsAppCampaignService::class),
        );

        $this->assertSame(WhatsAppCampaignRecipientStatus::Queued, $recipient->refresh()->status);
        $this->assertSame(WhatsAppCampaignStatus::Sending, $campaign->refresh()->status);
        Http::assertNothingSent();
        $this->assertTrue(collect($logged)->contains(
            fn (MessageLogged $event): bool => $event->message === 'WhatsApp campaign recipient job released until queued status is visible.',
        ));
    }

    public function test_campaign_job_failed_marks_recipient_failed_and_completes_campaign(): void
    {
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
            'send_interval_seconds' => 30,
        ]);
        app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->forceFill(['status' => WhatsAppCampaignRecipientStatus::Queued])->save();
        $campaign->forceFill(['status' => WhatsAppCampaignStatus::Sending])->save();

        (new SendWhatsAppCampaignRecipientJob($recipient->getKey()))
            ->failed(new RuntimeException('Max attempts exceeded'));

        $recipient->refresh();
        $campaign->refresh();

        $this->assertSame(WhatsAppCampaignRecipientStatus::Failed, $recipient->status);
        $this->assertSame('Max attempts exceeded', $recipient->error_message);
        $this->assertSame(1, $campaign->failed_count);
        $this->assertSame(WhatsAppCampaignStatus::Completed, $campaign->status);
    }

    public function test_watchdog_requeues_stuck_queued_recipients(): void
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
            'send_interval_seconds' => 30,
        ]);
        app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->forceFill([
            'status' => WhatsAppCampaignRecipientStatus::Queued,
            'queued_at' => now()->subMinutes(10),
            'attempted_at' => null,
        ])->save();
        $campaign->forceFill(['status' => WhatsAppCampaignStatus::Sending])->save();

        $this->artisan('whatsapp:requeue-stuck-campaigns', ['--minutes' => 5])
            ->expectsOutput('Destinatários reenfileirados: 1')
            ->assertSuccessful();

        Queue::assertPushed(SendWhatsAppCampaignRecipientJob::class, fn ($job): bool => $job->recipientId === $recipient->getKey());
    }

    public function test_watchdog_skips_recent_queued_recipients(): void
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
            'send_interval_seconds' => 30,
        ]);
        app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->forceFill([
            'status' => WhatsAppCampaignRecipientStatus::Queued,
            'queued_at' => now()->subMinute(),
        ])->save();
        $campaign->forceFill(['status' => WhatsAppCampaignStatus::Sending])->save();

        $this->artisan('whatsapp:requeue-stuck-campaigns', ['--minutes' => 5])
            ->expectsOutput('Destinatários reenfileirados: 0')
            ->assertSuccessful();

        Queue::assertNotPushed(SendWhatsAppCampaignRecipientJob::class);
    }

    public function test_campaign_job_logs_outbound_gate_deferral_without_calling_evolution(): void
    {
        Http::fake();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });
        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'secret',
            'services.evolution.outbound.circuit_failures' => 5,
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
            'send_interval_seconds' => 30,
        ]);
        app(WhatsAppCampaignService::class)->prepareRecipients($company, $campaign);
        $recipient = $campaign->recipients()->firstOrFail();
        $recipient->forceFill(['status' => WhatsAppCampaignRecipientStatus::Queued])->save();
        $campaign->forceFill(['status' => WhatsAppCampaignStatus::Sending])->save();

        $gate = app(WhatsAppOutboundGate::class);
        for ($i = 0; $i < 5; $i++) {
            $gate->recordFailure($company);
        }

        (new SendWhatsAppCampaignRecipientJob($recipient->getKey()))->handle(
            app(EvolutionApiClient::class),
            app(CompanySchedulingSettingService::class),
            app(CompanyWhatsAppInstanceService::class),
            app(WhatsAppCampaignService::class),
        );

        $this->assertSame(WhatsAppCampaignRecipientStatus::Queued, $recipient->refresh()->status);
        $this->assertSame(WhatsAppCampaignStatus::Sending, $campaign->refresh()->status);
        Http::assertNothingSent();
        $this->assertTrue(collect($logged)->contains(function (MessageLogged $event) use ($company): bool {
            return $event->message === 'WhatsApp outbound deferred.'
                && ($event->context['reason'] ?? null) === 'circuit_breaker'
                && (int) ($event->context['company_id'] ?? 0) === (int) $company->getKey();
        }));
    }

    protected function tinyJpeg(): string
    {
        return (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGf/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPwB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwB//9k=', true);
    }

    protected function tinyPng(): string
    {
        return (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);
    }
}
