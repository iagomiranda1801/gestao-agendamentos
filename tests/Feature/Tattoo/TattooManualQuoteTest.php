<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Jobs\HandleWhatsAppInboundMessageJob;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooAiConversation;
use App\Models\TattooManualQuoteDetection;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Services\Tattoo\ManualQuoteDetector;
use App\Services\WhatsApp\EvolutionWebhookService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

/** Orçamento digitado à mão no WhatsApp do estúdio, confirmado no WhatsApp pessoal do tatuador. */
class TattooManualQuoteTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private string $customer = '5511988888888';

    private string $artist = '5511977776666';

    private int $seq = 0;

    /** @return array<string, array{string, ?float, ?float}> */
    public static function detectorCases(): array
    {
        return [
            'currency' => ['Fica R$ 450 essa', 450.0, null],
            'cents' => ['o valor é 450,00 tá', 450.0, null],
            'thousands' => ['fechado em 1.200 a sessão inteira', 1200.0, null],
            'fica' => ['essa fica 600', 600.0, null],
            'with deposit' => ['Fica R$ 800, sinal de 150 pra garantir', 800.0, 150.0],
            'entrada' => ['total 1.500,00 com entrada de R$ 300', 1500.0, 300.0],
            'reais' => ['faço por 350 reais', 350.0, null],
            'time only' => ['tenho horário 14h ou 14:00 amanhã', null, null],
            'time range' => ['atendo das 14 às 22', null, null],
            'date' => ['pode ser dia 15/11?', null, null],
            'phone' => ['me chama no 11 98888-7777', null, null],
            'small value' => ['fica R$ 30 o retoque', null, null],
            'plain number' => ['tenho 3 sessões livres e 10 cm', null, null],
            'deposit only' => ['o sinal é 150', null, null],
        ];
    }

    #[DataProvider('detectorCases')]
    public function test_detector(string $text, ?float $total, ?float $deposit): void
    {
        $found = (new ManualQuoteDetector)->detect($text);

        $this->assertSame($total, $found['total'] ?? null, $text);
        $this->assertSame($deposit, $found['deposit'] ?? null, $text);
    }

    public function test_detector_lists_multiple_totals(): void
    {
        $found = (new ManualQuoteDetector)->detect('pequena fica R$ 400, maior fica R$ 700');

        $this->assertSame([400.0, 700.0], $found['totals']);
    }

    private function setupStudio(array $settings = []): array
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio, 'name' => 'GODS TATOO']);
        $company->schedulingSetting()->updateOrCreate([], array_merge(['ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key', 'tattoo_ai_enabled' => true,
            'tattoo_artist_notify_phone' => $this->artist], $settings));
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'mq-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.evolution.url' => 'https://evolution.test', 'services.evolution.key' => 'test-key']);
        Queue::fake([HandleWhatsAppInboundMessageJob::class]);
        Http::fake(fn () => Http::response(['key' => ['id' => 'sent-'.(++$this->seq)]]));

        return [$company->fresh(), $instance];
    }

    private function webhook(string $phone, string $text, bool $fromMe, ?string $id = null): void
    {
        app(EvolutionWebhookService::class)->handle(['event' => 'messages.upsert', 'instance' => 'mq-test',
            'data' => ['key' => ['remoteJid' => $phone.'@s.whatsapp.net', 'fromMe' => $fromMe, 'id' => $id ?? 'm'.(++$this->seq)],
                'pushName' => 'Fulano', 'message' => ['conversation' => $text]]]);
    }

    /** @return list<string> */
    private function textsTo(string $phone): array
    {
        return Http::recorded()->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request) => str_contains($request->url(), '/message/sendText/') && str_contains((string) ($request['number'] ?? ''), substr($phone, -8)))
            ->map(fn (Request $request) => (string) $request['text'])->values()->all();
    }

    public function test_detects_and_saves_quote_on_confirm(): void
    {
        [$company] = $this->setupStudio();
        Client::factory()->forCompany($company)->create(['name' => 'Bia Souza', 'phone' => '(11) 98888-8888']);

        $this->webhook($this->customer, 'Fica R$ 800, sinal de 150', true, 'artist-msg-1');

        $prompt = $this->textsTo($this->artist);
        $this->assertCount(1, $prompt);
        $this->assertSame('Orçamento detectado pro Bia Souza: total R$ 800,00, sinal R$ 150,00. Responde 1 pra salvar ou 2 pra ignorar.', $prompt[0]);
        $this->assertSame([], $this->textsTo($this->customer));

        $this->webhook($this->artist, '1', false);

        $quote = TattooQuote::query()->sole();
        $this->assertSame('800.00', $quote->amount_min);
        $this->assertSame('150.00', $quote->deposit_amount);
        $this->assertNotNull($quote->sent_at);
        $this->assertSame('Fica R$ 800, sinal de 150', $quote->message_snapshot);
        $request = TattooRequest::query()->sole();
        $this->assertSame('quote_sent', $request->status);
        $this->assertSame('whatsapp_manual', $request->source);
        $this->assertSame(1, Client::query()->count());
        $this->assertSame('saved', TattooManualQuoteDetection::query()->sole()->status);
        $conversation = TattooAiConversation::query()->sole();
        $this->assertSame($request->id, $conversation->tattoo_request_id);
        $this->assertFalse((bool) $conversation->human_takeover);
        $this->assertStringContainsString('Salvei o orçamento de R$ 800,00 pro Bia Souza', $this->textsTo($this->artist)[1]);
        $this->assertSame([], $this->textsTo($this->customer));
        Queue::assertNothingPushed();
    }

    public function test_confirm_creates_client_when_unknown(): void
    {
        $this->setupStudio();

        $this->webhook($this->customer, 'essa fica 600', true);
        $this->assertStringContainsString('sinal não definido', $this->textsTo($this->artist)[0]);
        $this->webhook($this->artist, '1', false);

        $client = Client::query()->sole();
        $this->assertSame('11988888888', substr($client->phone_normalized, -11));
        $this->assertNull(TattooQuote::query()->sole()->deposit_amount);
    }

    public function test_reply_two_ignores(): void
    {
        $this->setupStudio();

        $this->webhook($this->customer, 'fica R$ 500', true);
        $this->webhook($this->artist, '2', false);

        $this->assertSame(0, TattooQuote::query()->count());
        $this->assertSame('ignored', TattooManualQuoteDetection::query()->sole()->status);
        $this->assertStringContainsString('ignorei', $this->textsTo($this->artist)[1]);
    }

    public function test_dedupes_same_message_and_same_value(): void
    {
        $this->setupStudio();

        $this->webhook($this->customer, 'fica R$ 500', true, 'dup-1');
        $this->webhook($this->customer, 'fica R$ 500', true, 'dup-1');
        $this->webhook($this->customer, 'então, fica 500 mesmo', true, 'dup-2');

        $this->assertSame(1, TattooManualQuoteDetection::query()->count());
        $this->assertCount(1, $this->textsTo($this->artist));
    }

    public function test_non_quote_manual_message_does_not_notify(): void
    {
        $this->setupStudio();

        $this->webhook($this->customer, 'tenho horário dia 15/11 às 14h', true);

        $this->assertSame(0, TattooManualQuoteDetection::query()->count());
        $this->assertSame([], $this->textsTo($this->artist));
    }

    public function test_artist_phone_is_never_a_customer(): void
    {
        $this->setupStudio();

        $this->webhook($this->artist, 'oi, quero fazer uma tattoo', false);
        $this->webhook($this->artist, 'fica R$ 900', true);

        Queue::assertNothingPushed();
        $this->assertSame(0, TattooAiConversation::query()->count());
        $this->assertSame(0, TattooManualQuoteDetection::query()->count());
        $this->assertSame([], $this->textsTo($this->artist));
    }

    public function test_fixed_deposit_is_the_fallback(): void
    {
        $this->setupStudio(['tattoo_fixed_deposit_amount' => 100]);

        $this->webhook($this->customer, 'fica R$ 700', true);

        $this->assertStringContainsString('sinal R$ 100,00 (sinal fixo)', $this->textsTo($this->artist)[0]);
        $this->webhook($this->artist, '1', false);
        $this->assertSame('100.00', TattooQuote::query()->sole()->deposit_amount);
    }

    public function test_without_artist_phone_nothing_is_detected(): void
    {
        $this->setupStudio(['tattoo_artist_notify_phone' => null]);

        $this->webhook($this->customer, 'fica R$ 700', true);

        $this->assertSame(0, TattooManualQuoteDetection::query()->count());
    }
}
