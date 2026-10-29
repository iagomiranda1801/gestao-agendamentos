<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Filament\App\Resources\TattooAiConversations\Pages\ViewTattooAiConversation;
use App\Jobs\HandleWhatsAppInboundMessageJob;
use App\Models\Client;
use App\Models\CompanyWhatsAppInstance;
use App\Models\Professional;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Models\WhatsAppContact;
use App\Services\Tattoo\TattooAIConversationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

/**
 * Conversa real da GODS TATOO (20/10): nome igual ao de tatuador, frase como
 * nome do cadastro, pedido de orçamento virando ideia, cumprimento no meio,
 * loop no pedido do nome e nome sugerido pelo WhatsApp.
 */
class TattooAINameTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private string $phone = '553496857787';

    private function setupAI(): array
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio, 'name' => 'GODS TATOO']);
        $company->schedulingSetting()->updateOrCreate([], ['ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key']);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'gods-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key']);

        return [$company, $instance];
    }

    /** Gemini indisponível: o fluxo segue pelo roteiro. */
    private function withoutGemini(): void
    {
        Http::fake(fn () => Http::response(['key' => ['id' => 'sent']]));
    }

    /** @param  list<array<string, mixed>>  $responses */
    private function fakeGemini(array $responses): void
    {
        Http::fake(function ($request) use (&$responses) {
            if (str_contains($request->url(), 'generateContent')) {
                $next = array_shift($responses) ?? ['action' => 'ask', 'details' => [], 'reply' => ''];

                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($next)]]]]]]);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });
    }

    private function send($company, $instance, string $text, string $id, ?string $pushName = null): string
    {
        app(TattooAIConversationService::class)->handle($company, $instance, $this->phone.'@s.whatsapp.net',
            $this->phone, $text, $id, null, false, $pushName);

        return (string) TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('body');
    }

    private function conversation(): TattooAiConversation
    {
        return TattooAiConversation::query()->firstOrFail();
    }

    public function test_lone_name_after_name_prompt_is_the_client_even_when_an_artist_has_it(): void
    {
        [$company, $instance] = $this->setupAI();
        Professional::factory()->forCompany($company)->create(['name' => 'Lucas']);
        $this->withoutGemini();

        $this->send($company, $instance, 'opa', 'g1');
        $reply = $this->send($company, $instance, 'lucas', 'g2');

        $data = $this->conversation()->collected_data;
        $this->assertSame('Lucas', $data['name']);
        $this->assertSame('customer', $data['name_source']);
        $this->assertArrayNotHasKey('artist_preference', $data);
        $this->assertStringNotContainsString('Mencionou tatuador', (string) ($data['notes'] ?? ''));
        $this->assertStringStartsWith('Show, Lucas!', $reply);
        $this->assertStringContainsString('imagina a tattoo', $reply);
        $this->assertDatabaseHas('clients', ['company_id' => $company->id, 'name' => 'Lucas', 'phone_normalized' => $this->phone]);
    }

    public function test_capitalized_and_explicit_names_are_accepted_too(): void
    {
        [$company, $instance] = $this->setupAI();
        Professional::factory()->forCompany($company)->create(['name' => 'Lucas']);
        $this->withoutGemini();

        $this->send($company, $instance, 'opa', 'c1');
        $this->send($company, $instance, 'Lucas', 'c2');
        $this->assertSame('Lucas', $this->conversation()->collected_data['name']);

        $this->phone = '553400000001';
        $this->send($company, $instance, 'opa', 'e1');
        $this->send($company, $instance, 'me chamo lucas', 'e2');
        $other = TattooAiConversation::query()->where('phone_normalized', $this->phone)->firstOrFail();
        $this->assertSame('Lucas', $other->collected_data['name']);
        $this->assertArrayNotHasKey('artist_preference', $other->collected_data);
    }

    public function test_asking_for_an_artist_does_not_record_a_preference(): void
    {
        [$company, $instance] = $this->setupAI();
        Professional::factory()->forCompany($company)->create(['name' => 'Lucas']);
        $this->withoutGemini();

        $this->send($company, $instance, 'opa', 'p1');
        $this->send($company, $instance, 'Ana', 'p2');
        $this->send($company, $instance, 'quero fazer com o Lucas', 'p3');

        $data = $this->conversation()->collected_data;
        $this->assertSame('Ana', $data['name']);
        $this->assertArrayNotHasKey('artist_preference', $data);
        $this->assertStringNotContainsString('Lucas', (string) ($data['notes'] ?? ''));
    }

    public function test_full_artist_name_is_confirmed_once_and_the_answer_decides(): void
    {
        [$company, $instance] = $this->setupAI();
        Professional::factory()->forCompany($company)->create(['name' => 'Lucas Andrade']);
        $this->withoutGemini();

        $this->send($company, $instance, 'opa', 'a1');
        $this->assertSame('Lucas é você ou o tatuador?', $this->send($company, $instance, 'Lucas Andrade', 'a2'));
        $reply = $this->send($company, $instance, 'sou eu', 'a3');

        $data = $this->conversation()->collected_data;
        $this->assertSame('Lucas Andrade', $data['name']);
        $this->assertArrayNotHasKey('artist_preference', $data);
        $this->assertStringStartsWith('Show, Lucas!', $reply);
    }

    public function test_reference_conversation_moves_on_without_loops_or_phrase_as_idea(): void
    {
        [$company, $instance] = $this->setupAI();
        Professional::factory()->forCompany($company)->create(['name' => 'Lucas']);
        $this->withoutGemini();

        $intro = $this->send($company, $instance, 'opa', 'r1');
        $this->assertSame('Opa, tudo bem? Aqui é a assistente do *GODS TATOO*, o tatuador acompanha a conversa por aqui 🤙 Qual seu nome?', $intro);
        $this->assertSame('Não peguei seu nome. Como você se chama?', $this->send($company, $instance, 'queria fazer um outro orçamento de tatto', 'r2'));
        $this->assertArrayNotHasKey('description', $this->conversation()->collected_data ?? []);
        $this->send($company, $instance, 'lucas', 'r3');
        $this->send($company, $instance, 'o nome Maria em letra cursiva', 'r5');
        $this->send($company, $instance, 'no antebraço, parte de cima', 'r6');
        $this->assertStringContainsString('estilo em mente', $this->send($company, $instance, '25cm', 'r7'));
        $this->send($company, $instance, 'o estilo é Lettering', 'r8');

        $data = $this->conversation()->collected_data;
        $this->assertSame('Lucas', $data['name']);
        $this->assertSame('o nome Maria em letra cursiva', $data['description']);
        $this->assertSame('Lettering', $data['style']);
        $replies = TattooAiMessage::query()->where('direction', 'out')->orderBy('id')->pluck('body')->all();
        $this->assertSame(1, collect($replies)->filter(fn ($body) => str_contains((string) $body, 'assistente'))->count());
        $this->assertSame(1, substr_count(implode("\n", $replies), 'Opa, tudo bem?'));
        foreach (array_slice($replies, 1) as $body) {
            $this->assertDoesNotMatchRegularExpression('/^(Olá|Oi|Opa)\b/u', (string) $body);
        }
    }

    public function test_request_phrases_are_not_usable_ideas(): void
    {
        [$company, $instance] = $this->setupAI();
        $service = app(TattooAIConversationService::class);
        $check = fn (string $text): bool => (fn () => $this->hasUsableDescription($text))->call($service);

        $this->assertFalse($check('queria fazer um outro orçamento de tatto'));
        $this->assertFalse($check('queria fazer um orçamento'));
        $this->assertFalse($check('quanto custa uma tattoo?'));
        $this->assertTrue($check('quero uma rosa fine line no braço'));
    }

    public function test_model_greeting_in_the_middle_is_removed(): void
    {
        [$company, $instance] = $this->setupAI();
        TattooAiConversation::query()->create([
            'company_id' => $company->id, 'company_whatsapp_instance_id' => $instance->id,
            'phone_normalized' => $this->phone, 'remote_jid' => $this->phone.'@s.whatsapp.net', 'status' => 'collecting_information',
            'collected_data' => ['name' => 'Lucas', 'artist_preference' => 'Sem preferência', 'description' => 'Nome Maria cursivo',
                'body_placement' => 'Antebraço', 'size_description' => '25cm'],
        ])->messages()->create(['company_id' => $company->id, 'provider_message_id' => 'out-style', 'direction' => 'out',
            'status' => 'sent', 'body' => 'Já tem um estilo em mente? Tipo fine line, blackwork, lettering… se não souber, tranquilo.']);
        $this->fakeGemini([['action' => 'save_details', 'details' => ['style' => 'Lettering'],
            'reply' => 'Olá Lucas! Tudo bem? Você tem alguma foto de referência pra mandar?']]);

        $reply = $this->send($company, $instance, 'o estilo é Lettering', 'm1');

        $this->assertSame('Você tem alguma foto de referência pra mandar?', $reply);
    }

    public function test_name_prompt_stops_after_two_tries_and_flow_moves_on(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();

        $this->send($company, $instance, 'opa', 'l1');
        $this->send($company, $instance, 'prefiro não dizer', 'l2');
        $reply = $this->send($company, $instance, 'prefiro não falar', 'l3');

        $this->assertStringStartsWith('Tranquilo, vamos seguindo!', $reply);
        $this->assertStringContainsString('imagina a tattoo', $reply);
        $data = $this->conversation()->collected_data;
        $this->assertSame('placeholder', $data['name_source']);
        $this->assertStringContainsString('não informou o nome', (string) $data['notes']);
        $asks = TattooAiMessage::query()->where('direction', 'out')->pluck('body')
            ->filter(fn ($body) => preg_match('/nome\?|se chama\?/u', (string) $body))->count();
        $this->assertSame(2, $asks);
    }

    public function test_second_try_confirms_a_name_like_word(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();

        $this->send($company, $instance, 'opa', 'w1');
        $this->assertSame('Só pra confirmar: seu nome é Lucas?', $this->send($company, $instance, 'lucas aqui', 'w2'));
        $this->send($company, $instance, 'sim', 'w3');

        $this->assertSame('Lucas', $this->conversation()->collected_data['name']);
    }

    public function test_push_name_is_confirmed_instead_of_asking(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();

        $first = $this->send($company, $instance, 'oi', 'n1', 'Lucas');
        $this->assertSame('Opa, Lucas, tudo bem? Aqui é a assistente do *GODS TATOO*, o tatuador acompanha a conversa por aqui 🤙 É Lucas mesmo?', $first);
        $reply = $this->send($company, $instance, 'sim', 'n2', 'Lucas');

        $data = $this->conversation()->collected_data;
        $this->assertSame('Lucas', $data['name']);
        $this->assertSame('push_name', $data['name_source']);
        $this->assertStringStartsWith('Show, Lucas!', $reply);
        $this->assertDatabaseHas('clients', ['company_id' => $company->id, 'name' => 'Lucas', 'phone_normalized' => $this->phone]);
    }

    public function test_customer_correction_wins_over_push_name(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();

        $this->send($company, $instance, 'oi', 'k1', 'Lucas');
        $this->send($company, $instance, 'na verdade é Lucão', 'k2', 'Lucas');

        $data = $this->conversation()->collected_data;
        $this->assertSame('Lucão', $data['name']);
        $this->assertSame('customer', $data['name_source']);
    }

    public function test_invalid_push_name_is_ignored(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();

        $reply = $this->send($company, $instance, 'oi', 'i1', '🔥 Studio X');

        $this->assertStringEndsWith('Qual seu nome?', $reply);
        $this->assertArrayNotHasKey('_name_candidate', $this->conversation()->collected_data ?? []);
    }

    public function test_contact_book_name_has_priority_over_push_name(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();
        WhatsAppContact::query()->create(['company_id' => $company->id, 'company_whatsapp_instance_id' => $instance->id,
            'name' => 'Lucas Pereira', 'phone' => $this->phone, 'phone_normalized' => $this->phone]);

        $this->assertStringEndsWith('É Lucas mesmo?', $this->send($company, $instance, 'oi', 'b1', 'Lu'));
        $this->send($company, $instance, 'isso', 'b2');

        $data = $this->conversation()->collected_data;
        $this->assertSame('Lucas Pereira', $data['name']);
        $this->assertSame('contact_book', $data['name_source']);
    }

    public function test_valid_registered_name_is_kept_and_not_overwritten(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();
        Client::factory()->forCompany($company)->create(['name' => 'Lucas Silva', 'phone' => $this->phone, 'source' => 'whatsapp']);

        $reply = $this->send($company, $instance, 'oi', 'v1', 'Zé');

        $this->assertStringStartsWith('Opa, Lucas, tudo bem? Aqui é a assistente do *GODS TATOO*', $reply);
        $this->assertStringContainsString('imagina a tattoo', $reply);
        $this->assertSame('client', $this->conversation()->collected_data['name_source']);
        $this->assertDatabaseHas('clients', ['company_id' => $company->id, 'name' => 'Lucas Silva']);
    }

    public function test_phrase_saved_as_client_name_is_replaced_by_the_real_name(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();
        $client = Client::factory()->forCompany($company)->create(['name' => 'queria fazer um orçamento', 'phone' => $this->phone, 'source' => 'whatsapp']);

        $this->send($company, $instance, 'opa', 's1');
        $this->send($company, $instance, 'Lucas', 's2');

        $this->assertSame('Lucas', $client->fresh()->name);
    }

    public function test_valid_or_manual_client_names_are_never_overwritten(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();
        $manual = Client::factory()->forCompany($company)->create(['name' => 'Maria - unha gel', 'phone' => $this->phone, 'source' => 'manual']);

        $this->send($company, $instance, 'opa', 'o1');
        $this->send($company, $instance, 'Lucas', 'o2');

        $this->assertSame('Maria - unha gel', $manual->fresh()->name);
    }

    public function test_screen_shows_collected_name_when_registered_name_is_a_phrase(): void
    {
        [$company, $instance] = $this->setupAI();
        $client = Client::factory()->forCompany($company)->create(['name' => 'queria fazer um orçamento', 'phone' => $this->phone, 'source' => 'manual']);
        $conversation = TattooAiConversation::query()->create([
            'company_id' => $company->id, 'company_whatsapp_instance_id' => $instance->id, 'client_id' => $client->id,
            'phone_normalized' => $this->phone, 'remote_jid' => $this->phone.'@s.whatsapp.net', 'status' => 'collecting_information',
            'collected_data' => ['name' => 'Lucas'],
        ]);
        $page = new ViewTattooAiConversation;
        $page->record = $conversation;

        $this->assertSame('Conversa com Lucas', $page->getTitle());
    }

    public function test_robot_question_gets_an_honest_answer(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();

        $this->send($company, $instance, 'opa', 'rb1');
        $this->send($company, $instance, 'Ana', 'rb2');
        $reply = $this->send($company, $instance, 'vc é robô?', 'rb3');

        $this->assertStringContainsString('assistente do estúdio', $reply);
        $this->assertStringContainsString('tatuador', $reply);
        $this->assertFalse((bool) $this->conversation()->human_takeover);
    }

    public function test_cleanup_command_is_dry_run_by_default_and_applies_only_with_flag(): void
    {
        [$company, $instance] = $this->setupAI();
        Storage::fake('local');
        $phrase = Client::factory()->forCompany($company)->create(['name' => 'queria fazer um orçamento', 'phone' => $this->phone, 'source' => 'whatsapp']);
        $bare = Client::factory()->forCompany($company)->create(['name' => 'quanto custa', 'phone' => '5511900001234', 'source' => 'whatsapp']);
        $valid = Client::factory()->forCompany($company)->create(['name' => 'Maria de Souza', 'phone' => '5511900005678', 'source' => 'whatsapp']);
        $manual = Client::factory()->forCompany($company)->create(['name' => 'quero orçamento', 'phone' => '5511900009999', 'source' => 'manual']);
        TattooAiConversation::query()->create([
            'company_id' => $company->id, 'company_whatsapp_instance_id' => $instance->id, 'client_id' => $phrase->id,
            'phone_normalized' => $this->phone, 'remote_jid' => $this->phone.'@s.whatsapp.net', 'status' => 'collecting_information',
            'collected_data' => ['name' => 'Lucas'],
        ]);

        $this->artisan('clients:fix-invalid-names')->expectsOutputToContain('2 cliente(s) com nome inválido')->assertSuccessful();
        $this->assertSame('queria fazer um orçamento', $phrase->fresh()->name);
        $this->assertCount(1, Storage::disk('local')->files('reports'));

        $this->artisan('clients:fix-invalid-names', ['--apply' => true, '--by' => 'iago'])->assertSuccessful();
        $this->assertSame('Lucas', $phrase->fresh()->name);
        $this->assertSame('Cliente WhatsApp 1234', $bare->fresh()->name);
        $this->assertSame('Maria de Souza', $valid->fresh()->name);
        $this->assertSame('quero orçamento', $manual->fresh()->name);
    }

    public function test_webhook_passes_push_name_to_inbound_job(): void
    {
        Queue::fake();
        $this->postJson('/webhooks/evolution/gods-test', [
            'event' => 'messages.upsert',
            'instance' => 'gods-test',
            'data' => [
                'key' => ['id' => 'push-1', 'fromMe' => false, 'remoteJid' => $this->phone.'@s.whatsapp.net'],
                'pushName' => 'Lucas',
                'message' => ['conversation' => 'opa'],
            ],
        ])->assertOk();

        Queue::assertPushed(HandleWhatsAppInboundMessageJob::class, fn ($job) => $job->pushName === 'Lucas' && $job->messageId === 'push-1');
    }
}
