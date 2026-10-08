<?php

namespace Tests\Feature\AI;

use App\Models\CompanySchedulingSetting;
use App\Services\AI\CompanyAIService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class CompanyAIServiceTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_openai_and_openrouter_use_only_their_own_company_keys(): void
    {
        $openai = $this->createSchedulingCompany();
        $router = $this->createSchedulingCompany();
        $this->configure($openai->id, 'openai', 'gpt-4.1-mini', 'openai-company-key');
        $this->configure($router->id, 'openrouter', 'openai/gpt-4.1-mini', 'router-company-key');
        config(['services.gemini.api_key' => 'server-key']);

        Http::fake(fn (Request $request) => Http::response([
            'choices' => [['message' => ['content' => '{"reply":"ok"}']]],
            'usage' => ['prompt_tokens' => 12, 'completion_tokens' => 3],
        ]));

        $service = app(CompanyAIService::class);
        $this->assertSame('ok', $service->structured('Instrução', 'Entrada', context: ['company_id' => $openai->id])['reply']);
        $this->assertSame('ok', $service->structured('Instrução', 'Entrada', context: ['company_id' => $router->id])['reply']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer openai-company-key')
            && $request['model'] === 'gpt-4.1-mini');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer router-company-key')
            && $request['model'] === 'openai/gpt-4.1-mini');
        $this->assertSame(2, Http::recorded()->count());
    }

    public function test_key_is_encrypted_and_server_key_is_never_used_as_fallback(): void
    {
        $company = $this->createSchedulingCompany();
        $setting = $this->configure($company->id, 'gemini', 'gemini-2.5-flash', 'client-secret');
        $this->assertNotSame('client-secret', $setting->getRawOriginal('ai_api_key'));
        $this->assertArrayNotHasKey('ai_api_key', $setting->toArray());
        $setting->update(['ai_api_key' => null]);
        config(['services.gemini.api_key' => 'server-key']);
        Http::fake();

        $this->expectException(RuntimeException::class);
        try {
            app(CompanyAIService::class)->structured('Instrução', 'Entrada', context: ['company_id' => $company->id]);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_gemini_uses_the_company_key(): void
    {
        $company = $this->createSchedulingCompany();
        $this->configure($company->id, 'gemini', 'gemini-2.5-flash', 'gemini-company-key');
        Http::fake(fn () => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '{"reply":"ok"}']]]]],
        ]));

        app(CompanyAIService::class)->structured('Instrução', 'Entrada', context: ['company_id' => $company->id]);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'generateContent')
            && $request->hasHeader('x-goog-api-key', 'gemini-company-key'));
    }

    public function test_private_pdf_uses_company_key_for_openrouter(): void
    {
        $company = $this->createSchedulingCompany();
        $this->configure($company->id, 'openrouter', 'google/gemini-2.5-flash', 'pdf-company-key');
        Http::fake(fn () => Http::response(['choices' => [['message' => ['content' => '{"readable":true}']]]]));

        app(CompanyAIService::class)->structured('Leia', 'Comprovante', '%PDF-1.4', 'application/pdf',
            ['company_id' => $company->id]);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer pdf-company-key')
            && data_get($request->data(), 'messages.1.content.1.type') === 'file'
            && str_starts_with((string) data_get($request->data(), 'messages.1.content.1.file.file_data'),
                'data:application/pdf;base64,'));
    }

    private function configure(int $companyId, string $provider, string $model, string $key): CompanySchedulingSetting
    {
        $setting = CompanySchedulingSetting::query()->firstOrNew(['company_id' => $companyId]);
        $setting->company_id = $companyId;
        $setting->fill(['ai_provider' => $provider, 'ai_model' => $model, 'ai_api_key' => $key]);
        $setting->save();

        return $setting;
    }
}
