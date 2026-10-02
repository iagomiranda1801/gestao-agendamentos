<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Jobs\HandleWhatsAppInboundMessageJob;
use App\Models\CompanyWhatsAppInstance;
use App\Models\WhatsAppBotConversation;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\Tattoo\TattooWhatsAppBotService;
use App\Services\WhatsApp\EvolutionWebhookService;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class HumanTakeoverTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_manual_reply_from_business_pauses_bot_for_that_conversation(): void
    {
        Queue::fake();
        [$company, $instance] = $this->tattooCompany('tattoo-human');
        $phone = '5511987654321';

        app(TattooWhatsAppBotService::class)->handleIncoming($company, $instance, $phone.'@s.whatsapp.net', $phone, 'oi', 'in-1', null);

        app(EvolutionWebhookService::class)->handle($this->payload('tattoo-human', '551187654321', 'Oi! Aqui é o tatuador, me manda a ideia', true, 'out-1'));

        $takeover = app(WhatsAppHumanTakeover::class);
        $this->assertTrue($takeover->isPaused('tattoo-human', $phone));
        $this->assertFalse($takeover->isPaused('tattoo-human', '5511911112222'));
        $this->assertSame('human_takeover', WhatsAppBotConversation::query()->where('company_id', $company->id)->value('finished_reason'));
    }

    public function test_messages_sent_by_the_system_do_not_pause_the_bot(): void
    {
        Queue::fake();
        $this->tattooCompany('tattoo-echo');
        $takeover = app(WhatsAppHumanTakeover::class);
        $takeover->rememberBotSend('tattoo-echo', '5511987654321', 'Como posso te chamar?');

        app(EvolutionWebhookService::class)->handle($this->payload('tattoo-echo', '551187654321', 'Como posso te chamar?', true, 'out-2'));

        $this->assertFalse($takeover->isPaused('tattoo-echo', '5511987654321'));
    }

    public function test_paused_conversation_gets_no_bot_reply_but_others_do(): void
    {
        config(['services.evolution.url' => 'https://evolution.test', 'services.evolution.key' => 'test-key']);
        Http::fake(['evolution.test/*' => Http::response(['key' => ['id' => 'sent']], 200)]);
        [$company] = $this->tattooCompany('tattoo-job');
        $settings = app(CompanySchedulingSettingService::class)->getOrCreate($company);
        $settings->forceFill(['whatsapp_bot_enabled' => true])->save();

        $paused = '5511987654321';
        app(WhatsAppHumanTakeover::class)->pause('tattoo-job', $paused);
        app()->call([new HandleWhatsAppInboundMessageJob('tattoo-job', $paused.'@s.whatsapp.net', $paused, 'oi', 'job-1'), 'handle']);
        $this->assertSame(0, WhatsAppBotConversation::query()->where('company_id', $company->id)->count());
        Http::assertNothingSent();

        $other = '5511955554444';
        app()->call([new HandleWhatsAppInboundMessageJob('tattoo-job', $other.'@s.whatsapp.net', $other, 'oi', 'job-2'), 'handle']);
        $this->assertSame(1, WhatsAppBotConversation::query()->where('company_id', $company->id)->count());
    }

    /**
     * @return array{0: \App\Models\Company, 1: CompanyWhatsAppInstance}
     */
    protected function tattooCompany(string $instanceName): array
    {
        $company = $this->createSchedulingCompany([
            'business_profile' => CompanyProfile::TattooStudio,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
        ]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => $instanceName, 'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();

        return [$company, $instance];
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(string $instance, string $phone, string $text, bool $fromMe, string $id): array
    {
        return [
            'event' => 'messages.upsert',
            'instance' => $instance,
            'data' => [
                'key' => ['remoteJid' => $phone.'@s.whatsapp.net', 'fromMe' => $fromMe, 'id' => $id],
                'message' => ['conversation' => $text],
            ],
        ];
    }
}
