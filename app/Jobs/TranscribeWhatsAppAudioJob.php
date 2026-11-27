<?php

namespace App\Jobs;

use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Services\Tattoo\TattooManualQuoteService;
use App\Services\WhatsApp\Audio\WhatsAppAudioService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Transcreve uma nota de voz do tatuador e entrega o texto ao orçamento manual:
 * - artist_reply: áudio dele no WhatsApp pessoal ("1"/"2"/"sim"/"não") responde o aviso de orçamento;
 * - outbound_quote: áudio que ele mandou ao cliente pelo número do estúdio ("fica 800 reais").
 */
class TranscribeWhatsAppAudioJob implements ShouldQueue
{
    use Queueable;

    public const ARTIST_REPLY = 'artist_reply';

    public const OUTBOUND_QUOTE = 'outbound_quote';

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public string $instanceName,
        public string $phone,
        public string $messageId,
        public string $purpose,
        public ?int $audioSeconds = null,
        public ?int $audioBytes = null,
    ) {}

    public function handle(WhatsAppAudioService $audio, TattooManualQuoteService $quotes): void
    {
        $instance = CompanyWhatsAppInstance::query()->where('instance_name', $this->instanceName)->first();
        $company = $instance ? Company::query()->find($instance->company_id) : null;

        // Só estúdios com o número do tatuador configurado: nada de gastar com transcrição à toa.
        if (! $company || ! $quotes->isEnabledFor($this->instanceName)) {
            return;
        }

        try {
            $result = $audio->transcribe($company, $this->instanceName, $this->messageId, $this->audioSeconds, $this->audioBytes);
        } catch (LockTimeoutException) {
            $this->release(5);

            return;
        }

        if (! $result->isOk()) {
            return;
        }

        if ($this->purpose === self::ARTIST_REPLY) {
            $quotes->handleArtistMessage($this->instanceName, $this->phone, $result->text);

            return;
        }

        $quotes->detectFromOutbound($this->instanceName, $this->phone, $result->text, $this->messageId);
    }
}
