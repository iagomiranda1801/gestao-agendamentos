<?php

namespace App\Services\AI;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Transcreve áudio com um modelo Gemini pelo OpenRouter (chat completions + input_audio).
 * Não guarda nem registra o conteúdo do áudio nem a transcrição.
 */
class OpenRouterAudioTranscriber
{
    private const PROMPT = 'Você transcreve notas de voz do WhatsApp em português do Brasil. '
        .'Responda somente com a transcrição fiel do que foi falado, sem comentários, aspas ou explicações. '
        .'Escreva números, horários e valores em dinheiro com algarismos (ex.: R$ 450, 14h, 3 sessões). '
        .'Se não houver fala compreensível, responda exatamente: [inaudível]';

    /**
     * @param  array<string, mixed>  $context  campos extras para o log (sem conteúdo do áudio)
     * @return array{text: string, input_tokens: ?int, output_tokens: ?int, model: string}
     */
    public function transcribe(string $binary, string $format, string $apiKey, array $context = []): array
    {
        $model = (string) config('services.openrouter.audio_model', 'google/gemini-2.5-flash');
        $started = microtime(true);

        try {
            try {
                $response = $this->send($binary, $format, $apiKey, $model);
            } catch (RequestException $exception) {
                $converted = $this->retryFormat($exception, $binary, $format);
                if ($converted === null) {
                    throw $exception;
                }
                $response = $this->send($converted, 'mp3', $apiKey, $model);
            }

            $text = $this->extractText($response->json('choices.0.message.content'));
            if ($text === '' || mb_strtolower($text) === '[inaudível]' || mb_strtolower($text) === '[inaudivel]') {
                throw new RuntimeException('Áudio sem fala compreensível.');
            }

            $inputTokens = $response->json('usage.prompt_tokens');
            $outputTokens = $response->json('usage.completion_tokens');
            Log::info('WhatsApp audio transcription completed.', $context + [
                'provider' => 'openrouter', 'model' => $model,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'input_tokens' => $inputTokens, 'output_tokens' => $outputTokens,
            ]);

            return [
                'text' => mb_substr($text, 0, 4000),
                'input_tokens' => is_numeric($inputTokens) ? (int) $inputTokens : null,
                'output_tokens' => is_numeric($outputTokens) ? (int) $outputTokens : null,
                'model' => $model,
            ];
        } catch (\Throwable $exception) {
            Log::warning('WhatsApp audio transcription failed.', $context + [
                'provider' => 'openrouter', 'model' => $model,
                'duration_ms' => (int) ((microtime(true) - $started) * 1000),
                'error_type' => $exception::class,
                'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
            ]);

            throw $exception;
        }
    }

    private function send(string $binary, string $format, string $apiKey, string $model): Response
    {
        return Http::withToken($apiKey)->acceptJson()
            ->withHeaders(['HTTP-Referer' => (string) config('app.url'), 'X-Title' => (string) config('app.name')])
            ->timeout((int) config('services.ai.timeout', 30) + 30)
            ->post(rtrim((string) config('services.openrouter.base_url'), '/').'/chat/completions', [
                'model' => $model,
                'temperature' => 0,
                'max_tokens' => 2000,
                'messages' => [
                    ['role' => 'system', 'content' => self::PROMPT],
                    ['role' => 'user', 'content' => [
                        ['type' => 'text', 'text' => 'Transcreva este áudio.'],
                        ['type' => 'input_audio', 'input_audio' => ['data' => base64_encode($binary), 'format' => $format]],
                    ]],
                ],
            ])->throw();
    }

    /** Se o provedor recusou o formato e há ffmpeg configurado, devolve o áudio em mp3. */
    private function retryFormat(RequestException $exception, string $binary, string $format): ?string
    {
        $ffmpeg = config('services.openrouter.ffmpeg_path');
        if (blank($ffmpeg) || $format === 'mp3' || ! in_array($exception->response->status(), [400, 415, 422], true)) {
            return null;
        }

        $in = tempnam(sys_get_temp_dir(), 'wa-in-');
        $out = tempnam(sys_get_temp_dir(), 'wa-out-');
        try {
            file_put_contents($in, $binary);
            $result = Process::timeout(30)->run([(string) $ffmpeg, '-y', '-loglevel', 'error', '-i', $in, '-ac', '1', '-ar', '16000', '-f', 'mp3', $out]);
            $converted = $result->successful() ? file_get_contents($out) : false;

            return is_string($converted) && $converted !== '' ? $converted : null;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    private function extractText(mixed $content): string
    {
        if (is_array($content)) {
            $content = collect($content)->map(fn ($part) => is_array($part) ? ($part['text'] ?? '') : (string) $part)->implode(' ');
        }

        return trim((string) $content);
    }
}
