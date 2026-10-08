<?php

namespace App\Services\AI;

use App\Enums\CompanyRole;
use App\Models\Company;
use App\Models\CompanySchedulingSetting;
use Filament\Notifications\Notification;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class CompanyAIService
{
    /** @return array<string, mixed> */
    public function structured(string $instruction, string $input, ?string $binary = null, ?string $mime = null, array $context = []): array
    {
        $companyId = $context['company_id'] ?? null;
        $setting = $companyId ? CompanySchedulingSetting::query()->where('company_id', $companyId)->first() : null;
        if (! $setting || ! in_array($setting->ai_provider, ['gemini', 'openai', 'openrouter'], true)
            || blank($setting->ai_api_key) || blank($setting->ai_model)) {
            if ($companyId) {
                $this->notifyCredentialFailure((int) $companyId, 'não configurado', 0);
            }
            throw new RuntimeException('A IA da empresa precisa de provedor, modelo e chave próprios.');
        }

        $provider = $setting->ai_provider;
        $model = $setting->ai_model;
        $started = microtime(true);
        try {
            if ($provider === 'gemini') {
                $parts = [['text' => mb_substr($input, 0, 12000)]];
                if ($binary !== null && $mime !== null) {
                    $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($binary)]];
                }
                $response = Http::withHeaders(['x-goog-api-key' => $setting->ai_api_key])
                    ->acceptJson()->timeout((int) config('services.ai.timeout', 30))
                    ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                        'systemInstruction' => ['parts' => [['text' => $instruction]]],
                        'contents' => [['role' => 'user', 'parts' => $parts]],
                        'generationConfig' => ['responseMimeType' => 'application/json'],
                    ]);
                $response->throw();
                $raw = $response->json('candidates.0.content.parts.0.text');
                $inputTokens = $response->json('usageMetadata.promptTokenCount');
                $outputTokens = $response->json('usageMetadata.candidatesTokenCount');
            } else {
                $content = [['type' => 'text', 'text' => mb_substr($input, 0, 12000)]];
                if ($binary !== null && $mime !== null) {
                    $encoded = 'data:'.$mime.';base64,'.base64_encode($binary);
                    $content[] = $mime === 'application/pdf'
                        ? ['type' => 'file', 'file' => ['filename' => 'comprovante.pdf', 'file_data' => $encoded]]
                        : ['type' => 'image_url', 'image_url' => ['url' => $encoded]];
                }
                $url = $provider === 'openai'
                    ? 'https://api.openai.com/v1/chat/completions'
                    : 'https://openrouter.ai/api/v1/chat/completions';
                $response = Http::withToken($setting->ai_api_key)->acceptJson()
                    ->timeout((int) config('services.ai.timeout', 30))->post($url, [
                        'model' => $model,
                        'messages' => [
                            ['role' => 'system', 'content' => $instruction.' Responda somente com um objeto JSON.'],
                            ['role' => 'user', 'content' => $content],
                        ],
                        'response_format' => ['type' => 'json_object'],
                    ]);
                $response->throw();
                $raw = $response->json('choices.0.message.content');
                $inputTokens = $response->json('usage.prompt_tokens');
                $outputTokens = $response->json('usage.completion_tokens');
            }

            $parsed = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($parsed) || array_is_list($parsed)) {
                throw new RuntimeException('O provedor retornou JSON inválido.');
            }
            Log::info('Company AI request completed.', $context + [
                'provider' => $provider, 'model' => $model,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens,
            ]);

            return $parsed + ['_usage' => ['input' => $inputTokens, 'output' => $outputTokens]];
        } catch (Throwable $exception) {
            Log::warning('Company AI request failed.', $context + [
                'provider' => $provider, 'model' => $model,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'error_type' => $exception::class,
                'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
            ]);
            if ($exception instanceof RequestException && in_array($exception->response->status(), [401, 402, 403, 429], true)) {
                $this->notifyCredentialFailure((int) $companyId, $provider, $exception->response->status());
            }
            throw $exception;
        }
    }

    private function notifyCredentialFailure(int $companyId, string $provider, int $status): void
    {
        if (! Cache::add("company-ai-credential-alert:{$companyId}:{$status}", true, now()->addHour())) {
            return;
        }
        try {
            $company = Company::query()->find($companyId);
            $users = $company?->users()->wherePivot('is_active', true)->where('users.is_active', true)->get()
                ->filter(fn ($user) => in_array(
                    $user->pivot->role instanceof CompanyRole ? $user->pivot->role->value : $user->pivot->role,
                    [CompanyRole::CompanyAdmin->value, CompanyRole::Manager->value], true,
                ));
            if ($users?->isNotEmpty()) {
                Notification::make()->title('Verifique a chave de IA da empresa')
                    ->body($status === 0
                        ? 'Configure provedor, modelo e chave da empresa nas configurações da agenda para ativar a IA.'
                        : "O provedor {$provider} recusou uma chamada (HTTP {$status}). Confira a chave, a cota e o faturamento nas configurações da agenda.")
                    ->sendToDatabase($users->values());
            }
        } catch (Throwable $exception) {
            Log::warning('Company AI credential notification failed.', [
                'company_id' => $companyId, 'error_type' => $exception::class,
            ]);
        }
    }
}
