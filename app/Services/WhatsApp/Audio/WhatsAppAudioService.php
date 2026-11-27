<?php

namespace App\Services\WhatsApp\Audio;

use App\Models\Company;
use App\Models\CompanySchedulingSetting;
use App\Models\TattooAiMessage;
use App\Services\AI\OpenRouterAudioTranscriber;
use App\Services\WhatsApp\EvolutionApiClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nota de voz recebida no WhatsApp → texto, para seguir o atendimento normal
 * como se o cliente tivesse digitado. Idempotente por mensagem.
 */
class WhatsAppAudioService
{
    public function __construct(
        protected EvolutionApiClient $evolution,
        protected OpenRouterAudioTranscriber $transcriber,
    ) {}

    /**
     * @throws LockTimeoutException
     */
    public function transcribe(Company $company, string $instanceName, string $messageId, ?int $seconds = null, ?int $bytes = null): AudioTranscription
    {
        if (! (bool) config('services.whatsapp_audio.enabled', true)) {
            return AudioTranscription::failure(AudioTranscription::UNAVAILABLE, $seconds);
        }

        $cacheKey = "wa:audio:{$company->getKey()}:{$messageId}";

        return Cache::lock($cacheKey.':lock', 120)->block(10, function () use ($company, $instanceName, $messageId, $seconds, $bytes, $cacheKey): AudioTranscription {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return AudioTranscription::fromArray($cached);
            }

            $stored = TattooAiMessage::query()->where('company_id', $company->getKey())
                ->where('provider_message_id', $messageId)->where('source', 'audio')->first();
            if ($stored !== null) {
                return AudioTranscription::ok((string) $stored->body, $stored->transcription_model,
                    $stored->transcription_input_tokens, $stored->transcription_output_tokens, $stored->audio_seconds);
            }

            $result = $this->run($company, $instanceName, $messageId, $seconds, $bytes);
            if ($result->isOk()) {
                Cache::put($cacheKey, $result->toArray(), now()->addDay());
            }

            return $result;
        });
    }

    protected function run(Company $company, string $instanceName, string $messageId, ?int $seconds, ?int $bytes): AudioTranscription
    {
        $maxSeconds = (int) config('services.whatsapp_audio.max_seconds', 180);
        $maxBytes = (int) config('services.whatsapp_audio.max_bytes', 10 * 1024 * 1024);
        $context = ['company_id' => $company->getKey(), 'message_id' => $messageId, 'audio_seconds' => $seconds];

        if (($seconds !== null && $seconds > $maxSeconds) || ($bytes !== null && $bytes > $maxBytes)) {
            Log::info('WhatsApp audio skipped: too long.', $context + ['bytes' => $bytes]);

            return AudioTranscription::failure(AudioTranscription::TOO_LONG, $seconds);
        }

        $apiKey = $this->apiKey($company);
        if ($apiKey === null) {
            Log::warning('WhatsApp audio skipped: no OpenRouter key available.', $context);

            return AudioTranscription::failure(AudioTranscription::UNAVAILABLE, $seconds);
        }

        try {
            $media = $this->evolution->getMedia($instanceName, $messageId);
            $binary = base64_decode($media['base64'], true);
            if ($binary === false || $binary === '') {
                throw new \RuntimeException('Mídia de áudio inválida.');
            }
            if (strlen($binary) > $maxBytes) {
                Log::info('WhatsApp audio skipped: file too large.', $context + ['bytes' => strlen($binary)]);

                return AudioTranscription::failure(AudioTranscription::TOO_LONG, $seconds);
            }

            $transcript = $this->transcriber->transcribe($binary, $this->format($media['mimetype']), $apiKey, $context);
        } catch (Throwable $exception) {
            Log::warning('WhatsApp audio could not be transcribed.', $context + ['error_type' => $exception::class]);

            return AudioTranscription::failure(AudioTranscription::FAILED, $seconds);
        }

        return AudioTranscription::ok($transcript['text'], $transcript['model'], $transcript['input_tokens'], $transcript['output_tokens'], $seconds);
    }

    /** Chave da própria empresa se ela usa OpenRouter; senão a chave da plataforma. */
    protected function apiKey(Company $company): ?string
    {
        $setting = CompanySchedulingSetting::query()->where('company_id', $company->getKey())->first();
        if ($setting && $setting->ai_provider === 'openrouter' && filled($setting->ai_api_key)) {
            return (string) $setting->ai_api_key;
        }

        $platform = config('services.openrouter.api_key');

        return filled($platform) ? (string) $platform : null;
    }

    protected function format(?string $mimetype): string
    {
        $mime = strtolower((string) $mimetype);

        return match (true) {
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'flac') => 'flac',
            str_contains($mime, 'aac') => 'aac',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a') => 'm4a',
            default => 'ogg',
        };
    }
}
