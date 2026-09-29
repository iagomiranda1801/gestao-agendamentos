<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Filament\App\Resources\Appointments\Pages\CreateAppointment;
use App\Filament\App\Resources\TattooRequests\Pages\EditTattooRequest;
use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
use App\Jobs\HandleWhatsAppInboundMessageJob;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooRequest;
use App\Services\Company\CompanyModuleService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\Tattoo\TattooImageService;
use App\Services\Tattoo\TattooQuoteService;
use App\Services\Tattoo\TattooWhatsAppBotService;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotService;
use App\Services\WhatsApp\Bot\WhatsAppOrderLinkBotService;
use App\Services\WhatsApp\EvolutionApiClient;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class TattooQuoteFlowTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_bot_creates_request_without_blocking_calendar_and_quote_can_be_sent(): void
    {
        $company = $this->createSchedulingCompany([
            'business_profile' => CompanyProfile::TattooStudio,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
        ]);
        $user = $this->createCompanyUser($company);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'tattoo-test', 'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        $phone = '5511999999999';
        $jid = $phone.'@s.whatsapp.net';
        $bot = app(TattooWhatsAppBotService::class);
        $send = fn (string $body, string $id) => $bot->handleIncoming($company, $instance, $jid, $phone, $body, $id, null);

        $this->assertStringContainsString('Pedir orçamento', (string) $send('oi', '1'));
        $send('1', '2');
        $send('Ana Silva', '3');
        $send('Uma rosa com linhas finas', '4');
        $send('Antebraço', '5');
        $send('10 x 15 cm', '6');
        $this->assertStringContainsString('Confirma o pedido', (string) $send('0', '7'));
        $this->assertStringContainsString('Pedido recebido', (string) $send('1', '8'));
        $this->assertNull($send('1', '8'));

        $request = TattooRequest::query()->firstOrFail();
        $this->assertSame('awaiting_review', $request->status);
        $this->assertSame('Antebraço', $request->body_placement);
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('tattoo_requests', 1);

        $quotes = app(TattooQuoteService::class);
        $quote = $quotes->create($request, $user, [
            'price_type' => 'fixed', 'amount_min' => 450, 'sessions' => 1,
            'minutes_per_session' => 120, 'deposit_amount' => 50,
        ]);
        $this->assertSame(1, $quote->version);
        $this->assertStringContainsString('R$ 450,00', $quote->message_snapshot);

        config(['services.evolution.url' => 'https://evolution.test', 'services.evolution.key' => 'test-key']);
        Http::fake(['evolution.test/*' => Http::response(['key' => ['id' => 'sent']], 200)]);
        $quotes->send($quote);
        $this->assertSame('quote_sent', $request->fresh()->status);
        $this->assertNotNull($quote->fresh()->sent_at);
        $quotes->send($quote);
        Http::assertSentCount(1);
    }

    public function test_other_profiles_keep_their_default_modules(): void
    {
        $this->assertSame(
            [CompanyModule::Scheduling, CompanyModule::WhatsApp, CompanyModule::Finance],
            CompanyProfile::TattooStudio->defaultModules(),
        );
    }

    public function test_bot_stores_reference_image_privately_and_ignores_duplicate_webhook(): void
    {
        Storage::fake('local');
        config(['filesystems.tattoo_disk' => 'local']);
        config(['services.evolution.url' => 'https://evolution.test', 'services.evolution.key' => 'test-key']);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lS8AAAAASUVORK5CYII=');
        Http::fake(['evolution.test/*' => Http::response(['base64' => base64_encode($png)], 200)]);
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'tattoo-test', 'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        $bot = app(TattooWhatsAppBotService::class);
        $phone = '5511888888888';
        $jid = $phone.'@s.whatsapp.net';
        foreach (['oi', '1', 'Bia Souza', 'Um sol pequeno', 'Ombro', '5 cm'] as $index => $text) {
            $bot->handleIncoming($company, $instance, $jid, $phone, $text, 'step-'.$index, null);
        }
        $this->assertStringContainsString('Confirma', (string) $bot->handleIncoming($company, $instance, $jid, $phone, '', 'photo-1', 'image/png'));
        $this->assertNull($bot->handleIncoming($company, $instance, $jid, $phone, '', 'photo-1', 'image/png'));
        $image = TattooRequest::query()->firstOrFail()->images()->firstOrFail();
        Storage::disk('local')->assertExists($image->path);
        $this->assertSame('image/png', $image->mime_type);
        $this->assertDatabaseCount('tattoo_request_images', 1);
    }

    public function test_quote_page_is_available_only_for_tattoo_tenant(): void
    {
        $tattoo = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $user = $this->createCompanyUser($tattoo);
        $this->authenticateForAppTenant($user, $tattoo);
        $this->get(TattooRequestResource::getUrl('index'))->assertOk();
        $client = Client::factory()->forCompany($tattoo)->create();
        $request = new TattooRequest([
            'client_id' => $client->id, 'description' => 'Flor pequena',
            'body_placement' => 'Braço', 'source' => 'manual', 'status' => 'awaiting_review',
        ]);
        $request->company_id = $tattoo->id;
        $request->save();
        $this->get(TattooRequestResource::getUrl('edit', ['record' => $request]))->assertOk();
        Livewire::test(EditTattooRequest::class, ['record' => $request->id])
            ->callAction('quote', ['price_type' => 'fixed', 'amount_min' => 350, 'sessions' => 1]);
        $this->assertDatabaseHas('tattoo_quotes', ['tattoo_request_id' => $request->id, 'amount_min' => 350]);
    }

    public function test_approving_quote_redirects_to_appointment_and_downloads_pdf(): void
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $other = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $user = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($user, $company);
        $client = Client::factory()->forCompany($company)->create(['name' => 'Ana Silva']);
        $request = new TattooRequest([
            'client_id' => $client->id,
            'description' => 'Flor pequena',
            'body_placement' => 'Braço',
            'size_description' => '10 cm',
            'source' => 'manual',
            'status' => 'awaiting_review',
        ]);
        $request->company_id = $company->id;
        $request->save();
        $quote = app(TattooQuoteService::class)->create($request, $user, [
            'price_type' => 'fixed',
            'amount_min' => 350,
            'sessions' => 2,
            'deposit_amount' => 80,
            'conditions' => 'Sinal no agendamento.',
        ]);

        Livewire::test(EditTattooRequest::class, ['record' => $request->id])
            ->assertSee('Valor: R$ 350,00')
            ->assertSee('Sessões previstas: 2')
            ->assertSee('Rascunho')
            ->assertDontSee('Responda esta mensagem')
            ->callAction('approve_and_schedule')
            ->assertRedirect(AppointmentResource::getUrl('create', ['tattoo_request' => $request->id]));

        $this->assertSame('accepted', $request->fresh()->status);
        $this->assertNotNull($quote->fresh()->accepted_at);

        $this->get(route('tattoo.quotes.pdf', ['company' => $company, 'quote' => $quote]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get(route('tattoo.quotes.pdf', ['company' => $other, 'quote' => $quote]))
            ->assertNotFound();
    }

    public function test_add_photo_action_accepts_whatsapp_jpeg(): void
    {
        Storage::fake('local');
        config(['filesystems.tattoo_disk' => 'local']);
        $this->assertSame('local', config('livewire.temporary_file_upload.disk'));

        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $user = $this->createCompanyUser($company);
        $this->authenticateForAppTenant($user, $company);
        $client = Client::factory()->forCompany($company)->create();
        $request = new TattooRequest([
            'client_id' => $client->id, 'description' => 'Flor pequena',
            'body_placement' => 'Braço', 'source' => 'manual', 'status' => 'awaiting_review',
        ]);
        $request->company_id = $company->id;
        $request->save();

        $upload = UploadedFile::fake()->image('WhatsApp Image 2026-09-24 at 17.41.20.jpeg');

        Livewire::test(EditTattooRequest::class, ['record' => $request->id])
            ->callAction('add_image', ['image' => $upload])
            ->assertHasNoActionErrors();

        $image = $request->images()->first();
        $this->assertNotNull($image);
        $this->assertSame('image/jpeg', $image->mime_type);
        Storage::disk('local')->assertExists($image->path);

        $jpg = tempnam(sys_get_temp_dir(), 'tattoo_jpg_');
        file_put_contents($jpg, 'jpeg-bytes');
        try {
            $stored = app(TattooImageService::class)->upload($request, new class($jpg, 'WhatsApp Image.jpeg', 'image/jpg', null, true) extends UploadedFile
            {
                public function getMimeType(): ?string
                {
                    return 'image/jpg';
                }
            });
        } finally {
            @unlink($jpg);
        }

        $this->assertSame('image/jpeg', $stored->mime_type);
        $this->assertSame(2, $request->images()->count());
    }

    public function test_reference_image_is_isolated_by_company(): void
    {
        Storage::fake('local');
        config(['filesystems.tattoo_disk' => 'local']);
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $other = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $user = $this->createCompanyUser($company);
        $client = Client::factory()->forCompany($company)->create();
        $request = new TattooRequest(['client_id' => $client->id, 'description' => 'Rosa', 'body_placement' => 'Braço']);
        $request->company_id = $company->id;
        $request->save();
        $tmp = tempnam(sys_get_temp_dir(), 'tattoo_test_');
        file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lS8AAAAASUVORK5CYII='));
        try {
            $image = app(TattooImageService::class)->upload($request, new UploadedFile($tmp, 'rosa.png', 'image/png', null, true));
        } finally {
            @unlink($tmp);
        }
        $this->actingAs($user);
        $this->get(route('tattoo.images.download', ['company' => $other, 'image' => $image]))->assertNotFound();
        $this->get(route('tattoo.images.download', ['company' => $company, 'image' => $image]))->assertOk();
    }

    public function test_webhook_passes_photo_to_inbound_job(): void
    {
        Queue::fake();
        $this->postJson('/webhooks/evolution/tattoo-test', [
            'event' => 'messages.upsert',
            'instance' => 'tattoo-test',
            'data' => [
                'key' => ['id' => 'photo-42', 'fromMe' => false, 'remoteJid' => '5511999999999@s.whatsapp.net'],
                'message' => ['imageMessage' => ['mimetype' => 'image/png', 'caption' => 'Referência']],
            ],
        ])->assertOk();
        Queue::assertPushed(HandleWhatsAppInboundMessageJob::class, fn ($job) => $job->imageMime === 'image/png' && $job->messageId === 'photo-42');
    }

    public function test_inbound_job_routes_tattoo_company_to_quote_bot(): void
    {
        config(['services.evolution.url' => 'https://evolution.test', 'services.evolution.key' => 'test-key']);
        Http::fake(['evolution.test/*' => Http::response(['key' => ['id' => 'sent']], 200)]);
        $company = $this->createSchedulingCompany([
            'business_profile' => CompanyProfile::TattooStudio,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
        ]);
        app(CompanySchedulingSettingService::class)->getOrCreate($company)->update(['whatsapp_bot_enabled' => true]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'tattoo-test', 'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();

        (new HandleWhatsAppInboundMessageJob('tattoo-test', '5511999999999@s.whatsapp.net', '5511999999999', 'oi', 'hello-1'))->handle(
            app(WhatsAppBookingBotService::class), app(EvolutionApiClient::class),
            app(CompanyModuleService::class), app(CompanySchedulingSettingService::class),
            app(WhatsAppOrderLinkBotService::class),
        );
        Http::assertSent(fn ($request) => str_contains($request['text'], 'Pedir orçamento de tatuagem'));
    }

    public function test_accepted_quote_links_to_appointment_without_posting_revenue(): void
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $setup = $this->createBookableSetup($company);
        $request = new TattooRequest([
            'client_id' => $setup['client']->id, 'professional_id' => $setup['professional']->id,
            'description' => 'Rosa pequena', 'body_placement' => 'Braço', 'status' => 'accepted',
        ]);
        $request->company_id = $company->id;
        $request->save();
        $this->authenticateForAppTenant($setup['admin'], $company);

        Livewire::test(CreateAppointment::class)
            ->set('tattooRequestId', $request->id)
            ->fillForm([
                'client_id' => $setup['client']->id,
                'service_selection_mode' => 'defined',
                'service_id' => $setup['service']->id,
                'professional_id' => $setup['professional']->id,
                'appointment_date' => $setup['localStart']->toDateString(),
                'appointment_time' => $setup['localStart']->format('H:i'),
            ])->call('create')->assertHasNoFormErrors();

        $this->assertSame('booked', $request->fresh()->status);
        $this->assertNotNull($request->fresh()->appointment_id);
        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('receivables', 0);
    }
}
