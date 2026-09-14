<?php

namespace Tests\Feature\Orders;

use App\Enums\CompanyModule;
use App\Jobs\HandleWhatsAppInboundMessageJob;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\WhatsAppBotConversation;
use App\Services\Company\CompanyModuleService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\WhatsApp\Bot\WhatsAppBookingBotService;
use App\Services\WhatsApp\Bot\WhatsAppOrderLinkBotService;
use App\Services\WhatsApp\EvolutionApiClient;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesOrderFixtures;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class OrderWhatsAppLinkBotTest extends TestCase
{
    use CreatesOrderFixtures;
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
        ]);

        Http::fake([
            'evolution.test/*' => Http::response(['key' => ['id' => 'ok']], 200),
        ]);
    }

    public function test_restaurant_inbound_replies_with_public_order_link_only(): void
    {
        $setup = $this->createRestaurantSetup();
        $instance = $this->createInstance($setup['company']);

        $this->runInbound($instance, 'oi', 'rest-msg-1');

        $this->assertSame(0, WhatsAppBotConversation::query()->count());
        Http::assertSent(function ($request) use ($setup): bool {
            $text = (string) ($request['text'] ?? '');

            return str_contains($request->url(), '/message/sendText/')
                && str_contains($text, '/pedir/'.$setup['company']->slug)
                && str_contains($text, 'Peça pelo cardápio')
                && ! str_contains($text, 'Agendar');
        });
    }

    public function test_restaurant_does_not_fall_into_booking_bot_when_online_ordering_is_off(): void
    {
        $setup = $this->createRestaurantSetup(settingAttributes: [
            'online_ordering_enabled' => false,
        ]);
        $instance = $this->createInstance($setup['company']);

        $this->enablePublicBooking($setup['company'], ['whatsapp_bot_enabled' => true]);

        $this->runInbound($instance, 'oi', 'rest-offline-1');

        $this->assertSame(0, WhatsAppBotConversation::query()->count());
        Http::assertNothingSent();
    }

    public function test_restaurant_ignores_scheduling_bot_flags(): void
    {
        $setup = $this->createRestaurantSetup([
            'enabled_modules' => [
                CompanyModule::Orders->value,
                CompanyModule::WhatsApp->value,
                CompanyModule::Scheduling->value,
            ],
        ]);
        $this->enablePublicBooking($setup['company'], ['whatsapp_bot_enabled' => true]);
        $instance = $this->createInstance($setup['company']);

        $this->runInbound($instance, 'oi', 'rest-sched-1');

        $this->assertSame(0, WhatsAppBotConversation::query()->count());
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), '/pedir/'));
    }

    public function test_restaurant_toggle_off_sends_nothing(): void
    {
        $setup = $this->createRestaurantSetup(settingAttributes: [
            'whatsapp_order_link_bot_enabled' => false,
        ]);
        $instance = $this->createInstance($setup['company']);

        $this->runInbound($instance, 'oi', 'rest-toggle-off');

        Http::assertNothingSent();
        $this->assertSame(0, WhatsAppBotConversation::query()->count());
    }

    public function test_duplicate_restaurant_messages_do_not_resend_the_link(): void
    {
        $setup = $this->createRestaurantSetup();
        $instance = $this->createInstance($setup['company']);

        $this->runInbound($instance, 'oi', 'rest-dup-1');
        $this->runInbound($instance, 'oi', 'rest-dup-1');
        $this->runInbound($instance, 'quero pedir', 'rest-dup-2');

        Http::assertSentCount(1);
        $this->assertSame(0, WhatsAppBotConversation::query()->count());
    }

    public function test_failed_send_does_not_consume_cooldown_so_retry_can_deliver(): void
    {
        $setup = $this->createRestaurantSetup();
        $instance = $this->createInstance($setup['company']);
        $slug = $setup['company']->slug;

        $client = \Mockery::mock(EvolutionApiClient::class);
        $client->shouldReceive('sendText')
            ->once()
            ->andThrow(new RequestException(
                new \Illuminate\Http\Client\Response(new Response(500, [], 'fail')),
            ));
        $client->shouldReceive('sendText')
            ->once()
            ->withArgs(fn (string $instanceName, string $phone, string $text): bool => str_contains($text, '/pedir/'.$slug))
            ->andReturn(['key' => ['id' => 'ok']]);

        $this->runInbound($instance, 'oi', 'rest-retry-1', client: $client);
        $this->runInbound($instance, 'oi', 'rest-retry-1', client: $client);
        $this->runInbound($instance, 'quero pedir', 'rest-retry-2', client: $client);
    }

    public function test_salon_booking_bot_still_replies_to_inbound(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $company->update([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);
        $this->enablePublicBooking($company, ['whatsapp_bot_enabled' => true]);
        $instance = $this->createInstance($company);

        $this->runInbound($instance, 'oi', 'salon-msg-1', '5511977776666');

        $this->assertSame(1, WhatsAppBotConversation::query()->where('company_id', $company->id)->count());
        Http::assertSent(fn ($request): bool => str_contains((string) ($request['text'] ?? ''), 'Agendar um horário'));
    }

    protected function runInbound(
        CompanyWhatsAppInstance $instance,
        string $text,
        string $messageId,
        string $phone = '5511922221111',
        ?EvolutionApiClient $client = null,
    ): void {
        (new HandleWhatsAppInboundMessageJob(
            instanceName: $instance->instance_name,
            remoteJid: $phone.'@s.whatsapp.net',
            phone: $phone,
            text: $text,
            messageId: $messageId,
        ))->handle(
            app(WhatsAppBookingBotService::class),
            $client ?? app(EvolutionApiClient::class),
            app(CompanyModuleService::class),
            app(CompanySchedulingSettingService::class),
            app(WhatsAppOrderLinkBotService::class),
        );
    }

    protected function createInstance(Company $company): CompanyWhatsAppInstance
    {
        $instance = new CompanyWhatsAppInstance([
            'name' => 'Principal',
            'instance_name' => 'order-link-'.$company->getKey().'-'.uniqid(),
            'sender_phone' => '5511900000000',
            'status' => 'open',
            'is_default' => true,
            'connected_at' => now(),
        ]);
        $instance->company()->associate($company);
        $instance->save();

        return $instance->refresh();
    }
}
