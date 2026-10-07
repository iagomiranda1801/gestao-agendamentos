<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiService
{
    /** @return array<string, mixed> */
    public function structured(string $instruction, string $input, ?string $binary = null, ?string $mime = null, array $context = []): array
    {
        $key = (string) config('services.gemini.api_key');
        if ($key === '') {
            throw new RuntimeException('Gemini API não configurada.');
        }
        $model = (string) config('services.gemini.model', 'gemini-2.5-flash');
        $parts = [['text' => mb_substr($input, 0, 12000)]];
        if ($binary !== null && $mime !== null) {
            $parts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($binary)]];
        }
        $start = microtime(true);
        try {
            $response = Http::withHeaders(['x-goog-api-key' => $key])
                ->acceptJson()->timeout((int) config('services.gemini.timeout', 30))
                ->post('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent', [
                    'systemInstruction' => ['parts' => [['text' => $instruction]]],
                    'contents' => [['role' => 'user', 'parts' => $parts]],
                    'generationConfig' => ['responseMimeType' => 'application/json'],
                ]);
            $response->throw();
            $raw = $response->json('candidates.0.content.parts.0.text');
            $parsed = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($parsed) || array_is_list($parsed)) {
                throw new RuntimeException('Gemini retornou JSON inválido.');
            }
            Log::info('Gemini request completed.', $context + [
                'model' => $model, 'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'input_tokens' => $response->json('usageMetadata.promptTokenCount'),
                'output_tokens' => $response->json('usageMetadata.candidatesTokenCount'),
            ]);

            return $parsed + ['_usage' => [
                'input' => $response->json('usageMetadata.promptTokenCount'),
                'output' => $response->json('usageMetadata.candidatesTokenCount'),
            ]];
        } catch (\Throwable $exception) {
            Log::warning('Gemini request failed.', $context + [
                'model' => $model, 'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'error_type' => $exception::class,
                'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
            ]);
            throw $exception;
        }
    }
}
