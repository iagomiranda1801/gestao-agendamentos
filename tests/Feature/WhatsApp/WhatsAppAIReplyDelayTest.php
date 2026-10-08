<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Jobs\SendWhatsAppAIReplyJob;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Services\Tattoo\TattooAIConversationService;
use App\Services\WhatsApp\EvolutionWebhookService;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

/**
 * Respostas da IA saem com atraso humano (5 a 10 s) e "digitando...", sem
 * sleep no worker e sem perder as travas de segurança.
 */
class WhatsAppAIReplyDelayTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private string $phone = '5511988880000';

    /** @return array{0: Company, 1: CompanyWhatsAppInstance} */
    private function setupStudio(int $min = 5, int $max = 10): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 15:00:00', 'America/Sao_Paulo'));
        $company = $this->createSchedulingCompany([
            'business_profile' => CompanyProfile::TattooStudio,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
        ]);
        $company->schedulingSetting()->updateOrCreate([], ['ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key']);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'delay-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key',
            'services.evolution.ai_reply_delay' => ['min_seconds' => $min, 'max_seconds' => $max, 'typing_max_seconds' => 4]]);
        Http::fake(fn () => Http::response(['key' => ['id' => 'sent-'.uniqid()]]));

        return [$company, $instance];
    }

    private function receive(Company $company, CompanyWhatsAppInstance $instance, string $text, string $id, ?string $phone = null): void
    {
        $phone ??= $this->phone;
        app(TattooAIConversationService::class)->handle($company, $instance, $phone.'@s.whatsapp.net', $phone, $text, $id, null);
    }

    private function jobFor(string $id): SendWhatsAppAIReplyJob
    {
        $messageId = TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('id');

        return Queue::pushed(SendWhatsAppAIReplyJob::class)->first(fn (SendWhatsAppAIReplyJob $job) => $job->messageId === $messageId);
    }

    private function replyStatus(string $id): ?string
    {
        return TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('status');
    }

    /** @return Collection<int, Request> */
    private function sentTexts()
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), '/message/sendText/'))
            ->map(fn (array $pair) => $pair[0]);
    }

    public function test_reply_is_queued_with_human_delay_within_bounds_and_shows_typing(): void
    {
        [$company, $instance] = $this->setupStudio();
        Queue::fake();

        $offsets = [];
        foreach (range(1, 15) as $i) {
            $this->receive($company, $instance, 'Oi', 'b'.$i, '55119888800'.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
            $job = $this->jobFor('b'.$i);
            $this->assertSame('pending', $this->replyStatus('b'.$i));
            $this->assertSame(4000, $job->typingMs);
            $total = (int) now()->diffInSeconds($job->delay, true) + intdiv($job->typingMs, 1000);
            $this->assertGreaterThanOrEqual(5, $total);
            $this->assertLessThanOrEqual(10, $total);
            $offsets[] = $total;
        }
        $this->assertCount(0, $this->sentTexts(), 'Nada sai antes do atraso.');

        $job = $this->jobFor('b1');
        $this->travelTo($job->delay);
        $job->handle();
        $this->assertSame('sent', $this->replyStatus('b1'));
        $request = $this->sentTexts()->sole();
        $this->assertSame(4000, $request['delay']);
        $this->assertSame('Opa, tudo bem? Qual seu nome?', $request['text']);
    }

    public function test_zero_delay_disables_it_and_sends_inline_without_typing(): void
    {
        [$company, $instance] = $this->setupStudio(0, 0);
        Queue::fake();

        $this->receive($company, $instance, 'Oi', 'z1');

        Queue::assertNotPushed(SendWhatsAppAIReplyJob::class);
        $this->assertSame('sent', $this->replyStatus('z1'));
        $request = $this->sentTexts()->sole();
        $this->assertArrayNotHasKey('delay', $request->data());
    }

    public function test_takeover_or_whatsapp_pause_during_delay_prevents_the_send(): void
    {
        [$company, $instance] = $this->setupStudio();
        Queue::fake();

        $this->receive($company, $instance, 'Oi', 't1');
        TattooAiConversation::query()->firstOrFail()->update(['human_takeover' => true, 'status' => 'human_takeover']);
        $this->jobFor('t1')->handle();
        $this->assertSame('suppressed', $this->replyStatus('t1'));

        $other = '5511977770000';
        $this->receive($company, $instance, 'Oi', 't2', $other);
        app(WhatsAppHumanTakeover::class)->pause('delay-test', $other);
        $this->jobFor('t2')->handle();
        $this->assertSame('suppressed', $this->replyStatus('t2'));

        $this->assertCount(0, $this->sentTexts());
    }

    public function test_delayed_reply_echo_is_recognized_and_does_not_pause_the_bot(): void
    {
        [$company, $instance] = $this->setupStudio();
        Queue::fake();
        $webhooks = app(EvolutionWebhookService::class);
        $takeover = app(WhatsAppHumanTakeover::class);
        // Remote JID sem o nono dígito, como a Evolution costuma mandar.
        $jidPhone = '551188880000';

        $webhooks->handle($this->payload($jidPhone, 'Oi', false, 'in-1'));
        $this->receive($company, $instance, 'Oi', 'in-1');
        $job = $this->jobFor('in-1');
        $this->travel(8)->seconds();
        $job->handle();
        $this->assertSame('sent', $this->replyStatus('in-1'));

        // Eco da nossa própria mensagem, mesmo chegando depois da janela de 20 s.
        $this->travel(25)->seconds();
        $webhooks->handle($this->payload($jidPhone, 'Opa, tudo bem? Qual seu nome?', true, 'echo-1'));
        $this->assertFalse($takeover->isPaused('delay-test', $this->phone));

        // Saudação automática do WhatsApp Business logo após o cliente continua sem pausar.
        $webhooks->handle($this->payload($jidPhone, 'Ana', false, 'in-2'));
        $this->travel(2)->seconds();
        $webhooks->handle($this->payload($jidPhone, 'Seja bem-vindo! Em breve responderemos.', true, 'auto-1'));
        $this->assertFalse($takeover->isPaused('delay-test', $this->phone));

        // Resposta manual de verdade, fora da janela, pausa.
        $this->travel(5)->minutes();
        $webhooks->handle($this->payload($jidPhone, 'Oi! Aqui é o tatuador', true, 'human-1'));
        $this->assertTrue($takeover->isPaused('delay-test', $this->phone));
    }

    public function test_messages_during_the_delay_keep_order_and_spacing(): void
    {
        [$company, $instance] = $this->setupStudio();
        Queue::fake();

        $this->receive($company, $instance, 'Oi', 'o1');
        $this->travel(2)->seconds();
        $this->receive($company, $instance, 'bem e vc?', 'o2');
        $first = $this->jobFor('o1');
        $second = $this->jobFor('o2');
        $firstSendAt = CarbonImmutable::parse($first->delay)->addSeconds(4);
        $secondTypingStart = CarbonImmutable::parse($second->delay);
        $this->assertTrue($secondTypingStart->gte($firstSendAt->addSeconds(2)), 'A segunda só começa a digitar depois da primeira sair.');

        // Se a segunda rodar antes, espera a primeira.
        $second->withFakeQueueInteractions()->handle();
        $second->assertReleased(2);
        $this->assertSame('pending', $this->replyStatus('o2'));

        $first->handle();
        (new SendWhatsAppAIReplyJob($second->service, $second->messageId, $second->typingMs))->handle();
        $this->assertSame('sent', $this->replyStatus('o1'));
        $this->assertSame('sent', $this->replyStatus('o2'));
        $this->assertSame(['Opa, tudo bem? Qual seu nome?', 'Tudo certo por aqui, valeu! Qual seu nome?'],
            $this->sentTexts()->map(fn (Request $request) => $request['text'])->values()->all());
    }

    public function test_identical_reply_to_back_to_back_messages_is_sent_once(): void
    {
        [$company, $instance] = $this->setupStudio();
        Queue::fake();

        $this->receive($company, $instance, 'Oi', 'd1');
        $this->travel(1)->seconds();
        $this->receive($company, $instance, 'Olá', 'd2');
        $this->travel(6)->seconds();
        $this->jobFor('d1')->handle();
        $this->jobFor('d2')->handle();

        $this->assertSame('sent', $this->replyStatus('d1'));
        $this->assertSame('suppressed', $this->replyStatus('d2'));
        $this->assertCount(1, $this->sentTexts());

        // Já respondido antes da nova mensagem chegar: a mesma pergunta pode sair de novo.
        $this->travel(1)->minutes();
        $this->receive($company, $instance, 'oi?', 'd3');
        $this->jobFor('d3')->handle();
        $this->assertSame('sent', $this->replyStatus('d3'));
        $this->assertCount(2, $this->sentTexts());
    }

    /** @return array<string, mixed> */
    private function payload(string $phone, string $text, bool $fromMe, string $id): array
    {
        return [
            'event' => 'messages.upsert',
            'instance' => 'delay-test',
            'data' => [
                'key' => ['remoteJid' => $phone.'@s.whatsapp.net', 'fromMe' => $fromMe, 'id' => $id],
                'message' => ['conversation' => $text],
            ],
        ];
    }
}
