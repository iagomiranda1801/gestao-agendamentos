<?php

namespace Tests\Feature\AI;

use App\Services\AI\CompanyAIModelCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class CompanyAIModelCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_gemini_paginates_filters_and_preserves_model_versions(): void
    {
        Http::fakeSequence()->push([
            'models' => [
                ['name' => 'models/gemini-pro-001', 'displayName' => 'Gemini Pro', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/embedding-001', 'supportedGenerationMethods' => ['embedContent']],
            ],
            'nextPageToken' => 'next-page',
        ])->push([
            'models' => [['name' => 'models/gemini-flash', 'displayName' => 'Gemini Flash', 'supportedGenerationMethods' => ['generateContent']]],
        ]);

        $models = app(CompanyAIModelCatalog::class)->models(1, 'gemini', 'gemini-secret');
        $this->assertSame(['gemini-flash', 'gemini-pro-001'], array_keys($models));
        $this->assertSame('Gemini Pro (gemini-pro-001)', $models['gemini-pro-001']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => $request->hasHeader('x-goog-api-key', 'gemini-secret')
            && ($request['pageToken'] ?? null) === 'next-page');
    }

    public function test_openai_filters_non_chat_models_and_sorts_identifiers(): void
    {
        Http::fake(['https://api.openai.com/v1/models' => Http::response(['data' => array_map(
            fn (string $id): array => ['id' => $id],
            ['o3', 'gpt-4.1-mini', 'gpt-image-1', 'gpt-4o-audio-preview', 'gpt-4o-realtime-preview',
                'gpt-4o-mini-transcribe', 'text-embedding-3-small', 'whisper-1', 'tts-1', 'gpt-3.5-turbo-instruct'],
        )])]);

        $this->assertSame(['gpt-4.1-mini', 'o3'], array_keys(app(CompanyAIModelCatalog::class)->models(1, 'openai', 'openai-secret')));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer openai-secret'));
    }

    public function test_openrouter_filters_by_text_output_and_preserves_full_slug(): void
    {
        Http::fake(['https://openrouter.ai/api/v1/models' => Http::response(['data' => [
            ['id' => 'google/gemini-flash:free', 'name' => 'Gemini Flash Free', 'architecture' => ['output_modalities' => ['text']]],
            ['id' => 'image/model', 'architecture' => ['output_modalities' => ['image']]],
            ['id' => 'unknown/model'],
        ]])]);

        $this->assertSame(['google/gemini-flash:free' => 'Gemini Flash Free (google/gemini-flash:free)'],
            app(CompanyAIModelCatalog::class)->models(1, 'openrouter', 'router-secret'));
        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer router-secret'));
    }

    public function test_cache_is_isolated_by_company_provider_and_key_and_refresh_bypasses_it(): void
    {
        Http::fake(fn () => Http::response(['data' => [
            ['id' => 'gpt-4.1-mini', 'architecture' => ['output_modalities' => ['text']]],
        ]]));
        $catalog = app(CompanyAIModelCatalog::class);
        $catalog->models(1, 'openai', 'first-secret');
        $catalog->models(1, 'openai', 'first-secret');
        Http::assertSentCount(1);
        $catalog->models(2, 'openai', 'first-secret');
        $catalog->models(1, 'openai', 'second-secret');
        $catalog->models(1, 'openrouter', 'first-secret');
        Http::assertSentCount(4);
        $catalog->models(1, 'openai', 'first-secret', refresh: true);
        Http::assertSentCount(5);
        $this->travel(11)->minutes();
        $catalog->models(1, 'openai', 'first-secret');
        Http::assertSentCount(6);
    }

    public function test_invalid_key_is_not_exposed_and_failure_is_not_cached(): void
    {
        Http::fakeSequence()->push(['error' => ['message' => 'private-company-secret']], 401)
            ->push(['data' => [['id' => 'gpt-4.1-mini']]]);
        $catalog = app(CompanyAIModelCatalog::class);
        try {
            $catalog->models(1, 'openai', 'private-company-secret');
            $this->fail('Expected an authentication error.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('recusou a chave', $exception->getMessage());
            $this->assertStringNotContainsString('private-company-secret', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
        $this->assertArrayHasKey('gpt-4.1-mini', $catalog->models(1, 'openai', 'private-company-secret'));
        Http::assertSentCount(2);
    }

    public function test_timeout_has_a_safe_message(): void
    {
        Http::fake(fn () => Http::failedConnection());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Não foi possível conectar');
        app(CompanyAIModelCatalog::class)->models(1, 'openai', 'secret');
    }

    public function test_malformed_catalog_is_rejected(): void
    {
        Http::fake(fn () => Http::response(['unexpected' => []]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('lista de modelos inválida');
        app(CompanyAIModelCatalog::class)->models(1, 'openai', 'secret');
    }

    public function test_repeated_gemini_page_token_does_not_loop_forever(): void
    {
        Http::fake(fn () => Http::response(['models' => [], 'nextPageToken' => 'same-token']));
        try {
            app(CompanyAIModelCatalog::class)->models(1, 'gemini', 'secret');
            $this->fail('Expected a pagination error.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('concluir a lista', $exception->getMessage());
        }
        Http::assertSentCount(2);
    }

    public function test_missing_key_and_unsupported_provider_never_make_http_requests(): void
    {
        Http::fake();
        foreach ([['openai', ''], ['unknown', 'secret']] as [$provider, $key]) {
            try {
                app(CompanyAIModelCatalog::class)->models(1, $provider, $key);
                $this->fail('Expected configuration error.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Selecione o provedor', $exception->getMessage());
            }
        }
        Http::assertNothingSent();
    }
}
