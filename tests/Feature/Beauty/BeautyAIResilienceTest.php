<?php

namespace Tests\Feature\Beauty;

use App\Enums\CompanyModule;
use App\Enums\CompanyProfile;
use App\Jobs\HandleWhatsAppInboundMessageJob;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\Professional;
use App\Models\Service;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Services\Beauty\BeautyAIConversationService;
use App\Services\Beauty\BeautyAISchedulingService;
use App\Services\PublicBooking\OnlineBookingCatalogService;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use Database\Factories\ProfessionalServiceFactory;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

/**
 * Conversa real que ficou sem resposta: "Ola", "OI", "bEM E VC ?" e
 * "Quero entender mais sobre o seu serviço". Toda mensagem precisa de
 * resposta, mesmo com o Gemini fora do ar ou devolvendo lixo.
 */
class BeautyAIResilienceTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    private string $phone = '553484419606';

    /** @return array{0: Company, 1: CompanyWhatsAppInstance, 2: Service, 3: Professional} */
    private function setupSalon(): array
    {
        $company = $this->createSchedulingCompany([
            'business_profile' => CompanyProfile::Salon,
            'enabled_modules' => [CompanyModule::Scheduling->value, CompanyModule::WhatsApp->value],
        ]);
        $setup = $this->createBookableSetup($company);
        $setup['service']->update(['name' => 'Corte feminino', 'price' => 80, 'sort_order' => 0]);
        foreach (['Manicure' => true, 'Escova' => true, 'Coloração interna' => false] as $name => $online) {
            $service = Service::factory()->forCompany($company)->bookable()->active()->create([
                'name' => $name, 'duration_minutes' => 60, 'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0,
                'is_online_booking_enabled' => $online, 'sort_order' => 0,
            ]);
            ProfessionalServiceFactory::new()->forCompany($company)->create([
                'professional_id' => $setup['professional']->getKey(), 'service_id' => $service->getKey(), 'is_active' => true,
            ]);
        }
        $this->enablePublicBooking($company, ['beauty_ai_enabled' => true, 'online_auto_confirm' => true,
            'ai_provider' => 'gemini', 'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key']);
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'beauty-resilience',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        Client::factory()->forCompany($company)->create(['name' => 'IAGO MIRANDA GARCIA DA SILVA', 'phone' => $this->phone]);
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key']);

        return [$company, $instance, $setup['service']->fresh(), $setup['professional']];
    }

    /**
     * Cada item da fila é a resposta HTTP do Gemini para uma chamada.
     *
     * @param  list<\Closure(): mixed>  $responses
     */
    private function fakeGemini(array $responses): void
    {
        $queue = $responses;
        Http::fake(function (Request $request) use (&$queue) {
            if (str_contains($request->url(), 'generateContent')) {
                $next = array_shift($queue);

                return $next ? $next() : Http::response(['error' => ['code' => 503]], 503);
            }

            return Http::response(['key' => ['id' => 'sent-'.uniqid()]]);
        });
    }

    private function geminiText(string $text): \Closure
    {
        return fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => $text]]]]]]);
    }

    private function viaJob(string $text, string $id): string
    {
        app()->call([new HandleWhatsAppInboundMessageJob('beauty-resilience', $this->phone.'@s.whatsapp.net',
            $this->phone, $text, $id), 'handle']);

        return $this->replyFor($id);
    }

    private function send(Company $company, CompanyWhatsAppInstance $instance, string $text, string $id): string
    {
        app(BeautyAIConversationService::class)->handle($company, $instance, $this->phone.'@s.whatsapp.net',
            $this->phone, $text, $id, null);

        return $this->replyFor($id);
    }

    private function replyFor(string $id): string
    {
        $this->assertDatabaseHas('tattoo_ai_messages', ['provider_message_id' => $id, 'status' => 'processed']);
        $this->assertDatabaseHas('tattoo_ai_messages', ['provider_message_id' => 'reply:'.$id, 'status' => 'sent']);

        return (string) TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('body');
    }

    private function geminiCalls(): int
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'generateContent'))->count();
    }

    public function test_reported_conversation_gets_a_reply_to_every_message_even_with_gemini_down(): void
    {
        $this->setupSalon();
        $this->fakeGemini([]);

        $reply = $this->viaJob('Ola', 'r1');
        $this->assertStringStartsWith('Oi, Iago! Tudo bem? 😊', $reply);
        $this->assertStringContainsString('Qual serviço você quer fazer?', $reply);
        $this->assertStringContainsString('Corte feminino', $reply);

        $reply = $this->viaJob('OI', 'r2');
        $this->assertStringStartsWith('Oi, Iago!', $reply);
        $this->assertStringNotContainsString('Como posso te ajudar', $reply);

        $reply = $this->viaJob('bEM E VC ?', 'r3');
        $this->assertStringStartsWith('Tudo ótimo por aqui, obrigada! 😊', $reply);
        $this->assertStringContainsString('Qual serviço', $reply);

        $reply = $this->viaJob('Quero entender mais sobre o seu serviço', 'r4');
        $this->assertStringStartsWith("Claro! Aqui a gente faz:\n\n1. Corte feminino\n2. Escova\n3. Manicure", $reply);
        $this->assertStringContainsString('Qual deles te interessa, Iago?', $reply);
        $this->assertStringNotContainsString('Coloração interna', $reply);

        $this->assertSame(0, $this->geminiCalls());
        $conversation = TattooAiConversation::query()->firstOrFail();
        $this->assertFalse((bool) $conversation->human_takeover);
        $this->assertSame(8, $conversation->messages()->count());
    }

    public function test_new_contact_greeting_and_service_question_without_name(): void
    {
        [$company, $instance] = $this->setupSalon();
        Client::query()->where('phone_normalized', $this->phone)->delete();
        $this->fakeGemini([]);

        $this->assertSame("Oi, tudo bem? 😊\n\nQual seu nome?", $this->send($company, $instance, 'Ola', 'g1'));
        $this->assertSame("Tudo ótimo por aqui, obrigada! 😊\n\nQual seu nome?", $this->send($company, $instance, 'bem e vc?', 'g2'));
        $reply = $this->send($company, $instance, 'o que vocês fazem?', 'g3');
        $this->assertStringStartsWith("Claro! Aqui a gente faz:\n\n1. Corte feminino", $reply);
        $this->assertStringContainsString('me fala também seu nome', $reply);
        $this->assertSame(0, $this->geminiCalls());
    }

    public function test_gemini_quota_error_falls_back_to_the_next_question_and_logs_reason(): void
    {
        [$company, $instance] = $this->setupSalon();
        Log::spy();
        $this->fakeGemini([fn () => Http::response(['error' => ['code' => 429, 'status' => 'RESOURCE_EXHAUSTED']], 429)]);

        $reply = $this->send($company, $instance, 'Quero marcar um horário', 'q1');
        $this->assertStringStartsWith('Qual serviço você quer fazer?', $reply);
        $this->assertSame(1, $this->geminiCalls());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Beauty AI fallback reply.'
            && $context['reason'] === 'model_unavailable' && $context['http_status'] === 429)->once();
    }

    public function test_gemini_failure_still_understands_an_exact_service_name(): void
    {
        [$company, $instance] = $this->setupSalon();
        $this->fakeGemini([fn () => Http::response('upstream error', 500)]);

        $reply = $this->send($company, $instance, 'quero fazer manicure', 'm1');
        $this->assertStringStartsWith('Pra Manicure', $reply);
        $this->assertSame('offering_slots', TattooAiConversation::query()->firstOrFail()->status);
    }

    public function test_invalid_or_unexpected_gemini_json_never_leaves_the_client_without_reply(): void
    {
        [$company, $instance] = $this->setupSalon();
        Log::spy();
        $this->fakeGemini([
            $this->geminiText('isso não é json'),
            $this->geminiText('[{"action":"ask","reply":"Oi"}]'),
            $this->geminiText('{"action":"ask","details":"nome","reply":["lista"]}'),
            $this->geminiText('{"action":123,"details":{"info_topic":["x"]}}'),
            $this->geminiText('{"action":"info","details":{"info_topic":{"a":1}},"reply":42}'),
        ]);

        foreach (['Quero marcar', 'Pode ser?', 'Me ajuda', 'Hmm', 'E aí, como faço?'] as $i => $text) {
            $reply = $this->send($company, $instance, $text, 'j'.$i);
            $this->assertNotSame('', trim($reply), "Mensagem '{$text}' ficou sem resposta.");
        }

        $this->assertSame(5, $this->geminiCalls());
        $this->assertDatabaseCount('appointments', 0);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Beauty AI fallback reply.'
            && $context['reason'] === 'model_unavailable')->twice();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Beauty AI fallback reply.'
            && $context['reason'] === 'invalid_action')->once();
    }

    public function test_unexpected_internal_error_still_answers_and_logs(): void
    {
        [$company, $instance, $service] = $this->setupSalon();
        Log::spy();
        $this->app->bind(BeautyAISchedulingService::class, fn ($app) => new class($app->make(OnlineBookingCatalogService::class), $app->make(OnlineBookingService::class), $app->make(CompanySchedulingSettingService::class)) extends BeautyAISchedulingService
        {
            public function slots($company, $service, ?int $professionalId, ?string $fromDate = null,
                ?string $period = null, ?string $after = null, int $limit = 3): array
            {
                throw new RuntimeException('agenda indisponível');
            }
        });
        $this->fakeGemini([$this->geminiText(json_encode(['action' => 'save_details', 'details' => ['service_id' => $service->id], 'reply' => '']))]);

        $reply = $this->send($company, $instance, 'Quero um corte feminino', 'e1');
        $this->assertStringContainsString('Pode me mandar sua mensagem de novo?', $reply);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => $message === 'Beauty AI fallback reply.'
            && $context['reason'] === 'unexpected_error' && $context['error_type'] === RuntimeException::class)->once();
    }

    public function test_conversation_lock_timeout_requeues_the_message_instead_of_failing(): void
    {
        $this->setupSalon();
        $this->mock(BeautyAIConversationService::class)->shouldReceive('handle')->once()
            ->andThrow(new LockTimeoutException);

        $job = (new HandleWhatsAppInboundMessageJob('beauty-resilience', $this->phone.'@s.whatsapp.net', $this->phone, 'Oi', 'lock-1'))
            ->withFakeQueueInteractions();
        app()->call([$job, 'handle']);

        $job->assertReleased(5);
    }
}
