<?php

namespace Tests\Feature\WhatsApp;

use App\Enums\CompanyProfile;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooAiMessage;
use App\Models\TattooManualQuoteDetection;
use App\Services\WhatsApp\EvolutionWebhookService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

/** Nota de voz no WhatsApp → transcrição (Gemini via OpenRouter) → atendimento normal. */
class WhatsAppAudioTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private string $customer = '5511988888888';

    private string $artist = '5511977776666';

    private int $seq = 0;

    /** @var array<string, mixed> */
    private array $openRouterResponse;

    private function setupStudio(string $aiProvider = 'gemini', ?string $platformKey = 'platform-or-key'): array
    {
        Cache::flush();
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio, 'name' => 'GODS TATOO']);
        $company->schedulingSetting()->updateOrCreate([], ['ai_provider' => $aiProvider,
            'ai_model' => $aiProvider === 'gemini' ? 'gemini-2.5-flash' : 'google/gemini-2.5-flash',
            'ai_api_key' => 'company-key', 'tattoo_ai_enabled' => true, 'tattoo_artist_notify_phone' => $this->artist]);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'audio-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.evolution.url' => 'https://evolution.test', 'services.evolution.key' => 'test-key',
            'services.openrouter.api_key' => $platformKey, 'services.openrouter.audio_model' => 'google/gemini-2.5-flash',
            'services.openrouter.base_url' => 'https://openrouter.test/api/v1']);
        $this->openRouterResponse = ['choices' => [['message' => ['content' => 'Oi, quero uma rosa no antebraço']]],
            'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 15]];

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getBase64FromMediaMessage')) {
                return Http::response(['base64' => base64_encode('OggS-fake-audio'), 'mimetype' => 'audio/ogg; codecs=opus']);
            }
            if (str_contains($request->url(), 'openrouter.test')) {
                return Http::response($this->openRouterResponse);
            }
            if (str_contains($request->url(), 'generateContent')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
                    'action' => 'save_details', 'details' => ['name' => 'Ana'], 'reply' => 'Oi Ana, qual o tamanho?',
                ])]]]]], 'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 10]]);
            }

            return Http::response(['key' => ['id' => 'sent-'.(++$this->seq)]]);
        });

        return [$company->fresh(), $instance];
    }

    private function audioWebhook(string $phone, bool $fromMe, string $id, int $seconds = 8, int $bytes = 20000): void
    {
        app(EvolutionWebhookService::class)->handle(['event' => 'messages.upsert', 'instance' => 'audio-test',
            'data' => ['key' => ['remoteJid' => $phone.'@s.whatsapp.net', 'fromMe' => $fromMe, 'id' => $id],
                'pushName' => 'Ana', 'message' => ['audioMessage' => ['mimetype' => 'audio/ogg; codecs=opus',
                    'seconds' => $seconds, 'fileLength' => $bytes, 'ptt' => true]]]]);
    }

    private function hits(string $needle): int
    {
        return Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), $needle))->count();
    }

    /** @return list<string> */
    private function textsTo(string $phone): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_contains($r->url(), '/message/sendText/') && str_contains((string) ($r['number'] ?? ''), substr($phone, -8)))
            ->map(fn (Request $r) => (string) $r['text'])->values()->all();
    }

    public function test_customer_voice_note_is_transcribed_and_answered_like_typed_text(): void
    {
        $this->setupStudio();

        $this->audioWebhook($this->customer, false, 'aud-1');

        $in = TattooAiMessage::query()->where('direction', 'in')->where('provider_message_id', 'aud-1')->firstOrFail();
        $this->assertSame('Oi, quero uma rosa no antebraço', $in->body);
        $this->assertSame('audio', $in->source);
        $this->assertSame(8, $in->audio_seconds);
        $this->assertSame('google/gemini-2.5-flash', $in->transcription_model);
        $this->assertSame(120, $in->transcription_input_tokens);
        $this->assertSame(15, $in->transcription_output_tokens);

        $this->assertSame(1, $this->hits('getBase64FromMediaMessage'));
        $request = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), 'openrouter.test'));
        $this->assertSame('Bearer platform-or-key', $request->header('Authorization')[0]);
        $this->assertSame('google/gemini-2.5-flash', $request['model']);
        $part = collect($request['messages'][1]['content'])->firstWhere('type', 'input_audio');
        $this->assertSame('ogg', $part['input_audio']['format']);
        $this->assertSame(base64_encode('OggS-fake-audio'), $part['input_audio']['data']);

        // o modelo configurado da empresa (Gemini direto, aqui) respondeu em cima do texto transcrito
        $this->assertSame(1, $this->hits('generateContent'));
        $replies = $this->textsTo($this->customer);
        $this->assertNotEmpty($replies);
        $this->assertNotContains('Não consegui ouvir o áudio, pode me mandar por escrito?', $replies);
    }

    public function test_same_audio_delivered_twice_transcribes_once(): void
    {
        $this->setupStudio();

        $this->audioWebhook($this->customer, false, 'aud-dup');
        $this->audioWebhook($this->customer, false, 'aud-dup');

        $this->assertSame(1, $this->hits('openrouter.test'));
        $this->assertSame(1, $this->hits('getBase64FromMediaMessage'));
        $this->assertSame(1, TattooAiMessage::query()->where('direction', 'in')->count());
    }

    public function test_company_openrouter_key_is_preferred_over_platform_key(): void
    {
        $this->setupStudio('openrouter', null);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getBase64FromMediaMessage')) {
                return Http::response(['base64' => base64_encode('x'), 'mimetype' => 'audio/ogg']);
            }
            if (str_contains($request->url(), 'openrouter.test')) {
                return Http::response($this->openRouterResponse);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });

        $this->audioWebhook($this->customer, false, 'aud-co');

        $request = Http::recorded()->map(fn ($p) => $p[0])->first(fn (Request $r) => str_contains($r->url(), 'openrouter.test/api/v1/chat/completions') && isset($r['messages'][1]['content'][1]));
        $this->assertSame('Bearer company-key', $request->header('Authorization')[0]);
    }

    public function test_too_long_audio_is_skipped_with_polite_notice(): void
    {
        $this->setupStudio();

        $this->audioWebhook($this->customer, false, 'aud-long', seconds: 400);

        $this->assertSame(0, $this->hits('getBase64FromMediaMessage'));
        $this->assertSame(0, $this->hits('openrouter.test'));
        $this->assertSame(0, $this->hits('generateContent'));
        $in = TattooAiMessage::query()->where('direction', 'in')->firstOrFail();
        $this->assertSame('audio_failed', $in->source);
        $notice = collect($this->textsTo($this->customer))->first();
        $this->assertStringContainsString('mais curto', (string) $notice);
    }

    public function test_oversized_file_is_skipped(): void
    {
        $this->setupStudio();

        $this->audioWebhook($this->customer, false, 'aud-big', seconds: 30, bytes: 11 * 1024 * 1024);

        $this->assertSame(0, $this->hits('openrouter.test'));
        $this->assertStringContainsString('mais curto', (string) collect($this->textsTo($this->customer))->first());
    }

    public function test_transcription_failure_sends_fallback_and_never_calls_the_chat_model(): void
    {
        $this->setupStudio();
        $this->openRouterResponse = ['error' => ['message' => 'boom']];
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'getBase64FromMediaMessage')) {
                return Http::response(['base64' => base64_encode('x')]);
            }
            if (str_contains($request->url(), 'openrouter.test')) {
                return Http::response(['error' => 'down'], 500);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });

        $this->audioWebhook($this->customer, false, 'aud-fail');

        $this->assertSame(0, $this->hits('generateContent'));
        $this->assertContains('Não consegui ouvir o áudio, pode me mandar por escrito?', $this->textsTo($this->customer));
        $in = TattooAiMessage::query()->where('direction', 'in')->firstOrFail();
        $this->assertSame('audio_failed', $in->source);
        $this->assertStringNotContainsString('boom', $in->body);
    }

    public function test_without_any_openrouter_key_uses_fallback_notice(): void
    {
        $this->setupStudio('gemini', null);

        $this->audioWebhook($this->customer, false, 'aud-nokey');

        $this->assertSame(0, $this->hits('openrouter.test'));
        $this->assertContains('Não consegui ouvir o áudio, pode me mandar por escrito?', $this->textsTo($this->customer));
    }

    public function test_artist_audio_quote_is_detected_from_transcript(): void
    {
        [$company] = $this->setupStudio();
        Client::factory()->forCompany($company)->create(['name' => 'Bia Souza', 'phone' => '(11) 98888-8888']);
        $this->openRouterResponse = ['choices' => [['message' => ['content' => 'Fica R$ 800, sinal de 150']]], 'usage' => []];

        $this->audioWebhook($this->customer, true, 'artist-aud-1');

        $prompt = $this->textsTo($this->artist);
        $this->assertCount(1, $prompt);
        $this->assertStringContainsString('800', $prompt[0]);
        $this->assertSame(1, TattooManualQuoteDetection::query()->count());
    }

    public function test_artist_audio_reply_confirms_the_pending_quote(): void
    {
        [$company] = $this->setupStudio();
        Client::factory()->forCompany($company)->create(['name' => 'Bia Souza', 'phone' => '(11) 98888-8888']);
        app(EvolutionWebhookService::class)->handle(['event' => 'messages.upsert', 'instance' => 'audio-test',
            'data' => ['key' => ['remoteJid' => $this->customer.'@s.whatsapp.net', 'fromMe' => true, 'id' => 'typed-1'],
                'message' => ['conversation' => 'Fica R$ 800, sinal de 150']]]);
        $before = count($this->textsTo($this->artist));
        $this->openRouterResponse = ['choices' => [['message' => ['content' => '2']]], 'usage' => []];

        $this->audioWebhook($this->artist, false, 'artist-reply-aud');

        $this->assertSame(1, $this->hits('openrouter.test'));
        $this->assertGreaterThan($before, count($this->textsTo($this->artist)));
        $this->assertSame(0, TattooAiMessage::query()->where('provider_message_id', 'artist-reply-aud')->count());
    }

    public function test_audio_for_a_studio_without_artist_phone_does_not_spend_on_outbound_audio(): void
    {
        [$company] = $this->setupStudio();
        $company->schedulingSetting()->update(['tattoo_artist_notify_phone' => null]);

        $this->audioWebhook($this->customer, true, 'artist-aud-off');

        $this->assertSame(0, $this->hits('openrouter.test'));
    }
}
