<?php

namespace Tests\Feature\WhatsApp\BookingBot;

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
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class BookingBotDisabledTest extends TestCase
{
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

    public function test_job_does_nothing_when_bot_setting_off(): void
    {
        $company = $this->createSchedulingCompany();
        $company->update([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);
        $this->enablePublicBooking($company, ['whatsapp_bot_enabled' => false]);

        $instance = $this->createInstance($company);

        (new HandleWhatsAppInboundMessageJob(
            instanceName: $instance->instance_name,
            remoteJid: '5511922221111@s.whatsapp.net',
            phone: '5511922221111',
            text: 'oi',
            messageId: 'gate-off',
        ))->handle(
            app(WhatsAppBookingBotService::class),
            app(EvolutionApiClient::class),
            app(CompanyModuleService::class),
            app(CompanySchedulingSettingService::class),
            app(WhatsAppOrderLinkBotService::class),
        );

        $this->assertSame(0, WhatsAppBotConversation::query()->count());
        Http::assertNothingSent();
    }

    public function test_job_does_nothing_when_module_missing(): void
    {
        $company = $this->createSchedulingCompany();
        $company->update([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
            ],
        ]);
        $this->enablePublicBooking($company, ['whatsapp_bot_enabled' => true]);

        $instance = $this->createInstance($company);

        (new HandleWhatsAppInboundMessageJob(
            instanceName: $instance->instance_name,
            remoteJid: '5511922222222@s.whatsapp.net',
            phone: '5511922222222',
            text: 'oi',
            messageId: 'module-off',
        ))->handle(
            app(WhatsAppBookingBotService::class),
            app(EvolutionApiClient::class),
            app(CompanyModuleService::class),
            app(CompanySchedulingSettingService::class),
            app(WhatsAppOrderLinkBotService::class),
        );

        $this->assertSame(0, WhatsAppBotConversation::query()->count());
        Http::assertNothingSent();
    }

    public function test_job_does_nothing_when_instance_is_unknown(): void
    {
        $company = $this->createSchedulingCompany();
        $company->update([
            'enabled_modules' => [
                CompanyModule::Scheduling->value,
                CompanyModule::WhatsApp->value,
            ],
        ]);
        $this->enablePublicBooking($company, ['whatsapp_bot_enabled' => true]);

        (new HandleWhatsAppInboundMessageJob(
            instanceName: 'inexistente-instance',
            remoteJid: '5511922223333@s.whatsapp.net',
            phone: '5511922223333',
            text: 'oi',
            messageId: 'no-instance',
        ))->handle(
            app(WhatsAppBookingBotService::class),
            app(EvolutionApiClient::class),
            app(CompanyModuleService::class),
            app(CompanySchedulingSettingService::class),
            app(WhatsAppOrderLinkBotService::class),
        );

        $this->assertSame(0, WhatsAppBotConversation::query()->count());
        Http::assertNothingSent();
    }

    protected function createInstance(Company $company): CompanyWhatsAppInstance
    {
        $instance = new CompanyWhatsAppInstance([
            'name' => 'Principal',
            'instance_name' => 'bot-off-'.$company->getKey(),
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
