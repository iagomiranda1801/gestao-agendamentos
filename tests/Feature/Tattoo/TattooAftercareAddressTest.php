<?php

namespace Tests\Feature\Tattoo;

use App\Enums\CompanyProfile;
use App\Jobs\NotifyTattooHandoffJob;
use App\Models\CompanyWhatsAppInstance;
use App\Models\Professional;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Services\Tattoo\TattooAIConversationService;
use App\Services\Tattoo\TattooStudioInfo;
use App\Services\Tattoo\TattooStudioMenu;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

/** Cuidados com tatuagem já feita, endereço do estúdio e passagem para o tatuador. */
class TattooAftercareAddressTest extends TestCase
{
    use CreatesSchedulingFixtures;

    private string $phone = '553496850000';

    private function setupAI(array $settings = []): array
    {
        $company = $this->createSchedulingCompany(['business_profile' => CompanyProfile::TattooStudio, 'name' => 'GODS TATOO']);
        $company->schedulingSetting()->updateOrCreate([], array_merge(['ai_provider' => 'gemini',
            'ai_model' => 'gemini-2.5-flash', 'ai_api_key' => 'test-key'], $settings));
        $instance = new CompanyWhatsAppInstance(['name' => 'Principal', 'instance_name' => 'gods-test',
            'is_default' => true, 'status' => 'open']);
        $instance->company_id = $company->id;
        $instance->save();
        config(['services.gemini.api_key' => 'test-key', 'services.evolution.url' => 'https://evolution.test',
            'services.evolution.key' => 'test-key']);
        Queue::fake([NotifyTattooHandoffJob::class]);

        return [$company->fresh(), $instance];
    }

    private function withoutGemini(): void
    {
        Http::fake(fn () => Http::response(['key' => ['id' => 'sent']]));
    }

    /** Gemini respondendo outra coisa: a regra fixa vence. */
    private function withGemini(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'generateContent')) {
                return Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode(
                    ['action' => 'ask', 'details' => [], 'reply' => 'Me conta sua ideia de tattoo!'])]]]]]]);
            }

            return Http::response(['key' => ['id' => 'sent']]);
        });
    }

    private function send($company, $instance, string $text, string $id): string
    {
        app(TattooAIConversationService::class)->handle($company, $instance, $this->phone.'@s.whatsapp.net',
            $this->phone, $text, $id, null, false, null);

        return (string) TattooAiMessage::query()->where('provider_message_id', 'reply:'.$id)->value('body');
    }

    private function conversation(): TattooAiConversation
    {
        return TattooAiConversation::query()->firstOrFail();
    }

    public function test_aftercare_question_gets_the_configured_message_without_gemini(): void
    {
        [$company, $instance] = $this->setupAI(['tattoo_aftercare_message' => 'Lave com sabonete neutro e use a pomada X.']);
        $this->withoutGemini();

        $this->send($company, $instance, 'opa', 'a1');
        $reply = $this->send($company, $instance, 'Já fiz minha tattoo semana passada, quais os cuidados?', 'a2');

        $this->assertStringContainsString('Lave com sabonete neutro e use a pomada X.', $reply);
        $this->assertFalse((bool) $this->conversation()->human_takeover);
        Queue::assertNotPushed(NotifyTattooHandoffJob::class);
    }

    public function test_aftercare_uses_default_text_and_works_with_gemini(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withGemini();

        $reply = $this->send($company, $instance, 'como cuidar da cicatrização da tatuagem?', 'g1');

        $this->assertStringContainsString('sabonete neutro', $reply);
        $this->assertStringContainsString('assistente', $reply);
    }

    public function test_serious_issue_hands_over_to_configured_artist_and_pauses_ai(): void
    {
        [$company, $instance] = $this->setupAI(['tattoo_artist_name' => 'Gustavo']);
        $this->withoutGemini();

        $this->send($company, $instance, 'opa', 's1');
        $reply = $this->send($company, $instance, 'minha tattoo tá inflamada e com pus', 's2');

        $this->assertStringContainsString('Gustavo', $reply);
        $conversation = $this->conversation();
        $this->assertTrue((bool) $conversation->human_takeover);
        $this->assertSame('human_takeover', $conversation->status);
        Queue::assertPushed(NotifyTattooHandoffJob::class, fn ($job) => $job->conversationId === $conversation->id);

        $this->assertSame('', $this->send($company, $instance, 'oi?', 's3'));
        (new NotifyTattooHandoffJob($conversation->id, 'teste'))->handle();
    }

    public function test_asking_for_the_artist_hands_over_using_the_only_professional(): void
    {
        [$company, $instance] = $this->setupAI();
        Professional::factory()->forCompany($company)->create(['name' => 'Gustavo Lima']);
        $this->withGemini();

        $reply = $this->send($company, $instance, 'quero falar com o Gustavo', 't1');

        $this->assertStringContainsString('Gustavo', $reply);
        $this->assertTrue((bool) $this->conversation()->human_takeover);
        Queue::assertPushed(NotifyTattooHandoffJob::class);
    }

    public function test_retouch_without_artist_name_says_o_tatuador(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();

        $reply = $this->send($company, $instance, 'preciso fazer um retoque', 'r1');

        $this->assertStringContainsString('o tatuador', $reply);
        $this->assertTrue((bool) $this->conversation()->human_takeover);
    }

    public function test_address_uses_template_placeholders(): void
    {
        [$company, $instance] = $this->setupAI(['tattoo_studio_address' => 'Rua das Flores, 123 - Centro',
            'tattoo_address_message' => 'Estamos na {endereco}. Te esperamos no {empresa}!']);
        $this->withGemini();

        $this->send($company, $instance, 'opa', 'e1');
        $reply = $this->send($company, $instance, 'qual o endereço de vocês?', 'e2');

        $this->assertStringContainsString('Estamos na Rua das Flores, 123 - Centro. Te esperamos no GODS TATOO!', $reply);
        $this->assertFalse((bool) $this->conversation()->human_takeover);
    }

    public function test_missing_address_notifies_the_artist(): void
    {
        [$company, $instance] = $this->setupAI();
        $this->withoutGemini();

        $reply = $this->send($company, $instance, 'onde fica o estúdio?', 'm1');

        $this->assertStringContainsString('endereço', $reply);
        $this->assertTrue((bool) $this->conversation()->human_takeover);
        Queue::assertPushed(NotifyTattooHandoffJob::class);
    }

    public function test_body_placement_question_is_not_an_address_question(): void
    {
        $this->assertFalse(TattooStudioInfo::isAddressQuestion('onde fica melhor no braco?'));
        $this->assertFalse(TattooStudioInfo::isAddressQuestion('quero no antebraco'));
        $this->assertTrue(TattooStudioInfo::isAddressQuestion('manda a localizacao'));
    }

    public function test_menu_options_five_and_six(): void
    {
        [$company, $instance] = $this->setupAI(['tattoo_custom_flow_enabled' => true,
            'tattoo_studio_address' => 'Av. Brasil, 10']);
        $this->withoutGemini();

        $this->assertStringContainsString('1 Fazer', $this->send($company, $instance, 'oi', 'n1'));
        $this->assertStringContainsString('sabonete neutro', $this->send($company, $instance, '5', 'n2'));
        $this->assertStringContainsString('Av. Brasil, 10', $this->send($company, $instance, '6', 'n3'));
        $this->assertSame('awaiting_menu', $this->conversation()->status);
        $this->assertStringContainsString('6 Endereço do estúdio', TattooStudioMenu::TEXT);
    }
}
