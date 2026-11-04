<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Jobs\NotifyTattooHandoffJob;
use App\Models\Appointment;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\Professional;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Services\Tattoo\TattooAIConversationService;
use App\Services\Tattoo\TattooWhatsAppBotService;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

/** A assistente da tatuagem olha o contexto (tatuador, orçamento, horário) antes de responder. */
class TattooConversationContextTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private string $phone = '5511988888888';

    private function setupAI(array $settings = []): array
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio, 'name' => 'GODS TATOO',
            'timezone' => 'America/Sao_Paulo']);
        $company->schedulingSetting()->updateOrCreate([], array_merge(['ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key', 'tattoo_ai_enabled' => true], $settings));
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'ctx-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key']);
        Queue::fake([NotifyTattooHandoffJob::class]);
        Http::fake(fn () => Http::response(['key' => ['id' => 'sent']]));

        return [$company->fresh(), $instance];
    }

    private function send($company, $instance, string $text, string $id): string
    {
        $paused = app(WhatsAppHumanTakeover::class)->isPaused($instance->instance_name, $this->phone);
        app(TattooAIConversationService::class)->handle($company, $instance, $this->phone.'@s.whatsapp.net',
            $this->phone, $text, $id, null, $paused, null);

        return (string) TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('body');
    }

    private function conversation(): TattooAiConversation
    {
        return TattooAiConversation::query()->firstOrFail();
    }

    private function quotedRequest($company, Client $client, float $deposit = 0): TattooRequest
    {
        $request = new TattooRequest(['client_id' => $client->id, 'description' => 'Rosa fineline',
            'body_placement' => 'Antebraço', 'source' => 'manual', 'status' => 'quote_sent']);
        $request->company_id = $company->id;
        $request->save();
        $quote = new TattooQuote();
        $quote->forceFill(['company_id' => $company->id, 'tattoo_request_id' => $request->id, 'version' => 1,
            'price_type' => 'fixed', 'amount_min' => 450, 'sessions' => 1, 'deposit_amount' => $deposit,
            'message_snapshot' => 'Orçamento: R$ 450', 'sent_at' => now()])->save();

        return $request;
    }

    public function test_manual_takeover_persists_beyond_two_hours(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->send($company, $instance, 'opa', 'p1');

        app(WhatsAppHumanTakeover::class)->pause($instance->instance_name, $this->phone);
        $this->travel(3)->hours();

        $this->assertFalse(app(WhatsAppHumanTakeover::class)->isPaused($instance->instance_name, $this->phone));
        $this->assertSame('', $this->send($company, $instance, 'oi, tudo bem?', 'p2'));
        $this->assertTrue((bool) $this->conversation()->human_takeover);
    }

    public function test_artist_first_chat_stays_silent(): void
    {
        [$company, $instance] = $this->setupAI();

        app(WhatsAppHumanTakeover::class)->pause($instance->instance_name, $this->phone);
        $this->assertTrue((bool) $this->conversation()->human_takeover);
        $this->travel(3)->hours();

        $this->assertSame('', $this->send($company, $instance, 'oi', 'f1'));
        $this->assertSame(1, TattooAiConversation::query()->count());
    }

    public function test_scheduling_keyword_resumes_the_ai_without_intro(): void
    {
        [$company, $instance] = $this->setupAI();
        app(WhatsAppHumanTakeover::class)->pause($instance->instance_name, $this->phone);

        $reply = $this->send($company, $instance, 'quero agendar', 'k1');

        $this->assertNotSame('', $reply);
        $this->assertStringNotContainsString('assistente', $reply);
        $this->assertFalse((bool) $this->conversation()->human_takeover);
        $this->assertFalse(app(WhatsAppHumanTakeover::class)->isPaused($instance->instance_name, $this->phone));
    }

    public function test_upcoming_appointment_notice_then_reschedule_hands_over(): void
    {
        [$company, $instance] = $this->setupAI();
        $client = Client::factory()->forCompany($company)->create(['name' => 'Bia Souza', 'phone' => '(11) 98888-8888']);
        $professional = Professional::factory()->forCompany($company)->create(['name' => 'Gustavo Lima']);
        $start = now()->addDays(3)->setTime(18, 0);
        Appointment::factory()->forCompany($company)->create(['client_id' => $client->id,
            'professional_id' => $professional->id, 'start_at' => $start, 'end_at' => $start->copy()->addHour()]);

        $reply = $this->send($company, $instance, 'oi', 'u1');

        $this->assertStringContainsString('Opa, Bia! Vi que você tem horário marcado dia', $reply);
        $this->assertStringContainsString('com Gustavo', $reply);
        $this->assertStringContainsString('1 Remarcar', $reply);
        $this->assertStringNotContainsString('assistente', $reply);

        $reply = $this->send($company, $instance, '1', 'u2');
        $this->assertStringContainsString('Gustavo', $reply);
        $this->assertStringContainsString('remarcar', $reply);
        $this->assertTrue((bool) $this->conversation()->human_takeover);
        Queue::assertPushed(NotifyTattooHandoffJob::class);
    }

    public function test_upcoming_appointment_notice_is_shown_only_once(): void
    {
        [$company, $instance] = $this->setupAI();
        $client = Client::factory()->forCompany($company)->create(['name' => 'Bia Souza', 'phone' => '(11) 98888-8888']);
        $start = now()->addDays(3)->setTime(18, 0);
        Appointment::factory()->forCompany($company)->create(['client_id' => $client->id,
            'start_at' => $start, 'end_at' => $start->copy()->addHour()]);

        $this->assertStringContainsString('horário marcado', $this->send($company, $instance, 'oi', 'o1'));
        $this->assertStringNotContainsString('horário marcado', $this->send($company, $instance, 'qual o valor de uma rosa?', 'o2'));
        $this->assertStringNotContainsString('horário marcado', $this->send($company, $instance, 'oi', 'o3'));
        $this->assertFalse((bool) $this->conversation()->human_takeover);
    }

    public function test_existing_quote_continues_without_menu(): void
    {
        [$company, $instance] = $this->setupAI(['tattoo_custom_flow_enabled' => true]);
        $client = Client::factory()->forCompany($company)->create(['name' => 'Bia Souza', 'phone' => '(11) 98888-8888']);
        $request = $this->quotedRequest($company, $client);

        $reply = $this->send($company, $instance, 'aceito', 'q1');

        $this->assertStringNotContainsString('1 Fazer', $reply);
        $this->assertStringNotContainsString('assistente', $reply);
        $this->assertStringContainsString('Fechado', $reply);
        $this->assertSame($request->id, $this->conversation()->tattoo_request_id);
        $this->assertSame('accepted', $request->fresh()->status);
    }

    public function test_new_customer_still_gets_the_menu(): void
    {
        [$company, $instance] = $this->setupAI(['tattoo_custom_flow_enabled' => true]);

        $this->assertStringContainsString('1 Fazer', $this->send($company, $instance, 'oi', 'n1'));
    }

    public function test_old_bot_stays_silent_after_manual_takeover(): void
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'old-bot', 'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        $bot = app(TattooWhatsAppBotService::class);
        $jid = $this->phone.'@s.whatsapp.net';

        $this->assertNotNull($bot->handleIncoming($company, $instance, $jid, $this->phone, 'oi', 'b1', null));
        app(WhatsAppHumanTakeover::class)->pause('old-bot', $this->phone);
        $this->travel(3)->hours();

        $this->assertNull($bot->handleIncoming($company, $instance, $jid, $this->phone, 'oi', 'b2', null));
        $this->assertNull($bot->handleIncoming($company, $instance, $jid, $this->phone, 'quanto fica uma tattoo?', 'b3', null));
        $this->assertNotNull($bot->handleIncoming($company, $instance, $jid, $this->phone, 'quero agendar', 'b4', null));
    }
}
