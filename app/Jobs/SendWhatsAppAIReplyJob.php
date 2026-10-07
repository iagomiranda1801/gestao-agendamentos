<?php

namespace App\Jobs;

use App\Models\TattooAiMessage;
use App\Services\AI\WhatsAppAIConversationService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envia a resposta da IA depois do atraso "humano". O worker só fica preso
 * nos poucos segundos de "digitando..." da Evolution, não no atraso todo.
 */
class SendWhatsAppAIReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 30;

    public int $timeout = 60;

    /** @param  class-string<WhatsAppAIConversationService>  $service */
    public function __construct(public string $service, public int $messageId, public int $typingMs = 0) {}

    public function handle(): void
    {
        if (! is_subclass_of($this->service, WhatsAppAIConversationService::class)) {
            return;
        }
        try {
            $outcome = app($this->service)->deliver($this->messageId, $this->typingMs);
        } catch (LockTimeoutException) {
            $this->release(3);

            return;
        } catch (Throwable $exception) {
            // Falha no envio já fica registrada como "failed": reenviar poderia duplicar a mensagem.
            // Se nem chegou a enviar (ainda "pending"), tenta de novo.
            if (TattooAiMessage::query()->whereKey($this->messageId)->value('status') === 'pending') {
                throw $exception;
            }

            return;
        }
        if ($outcome === 'wait') {
            // Uma resposta anterior da mesma conversa ainda não saiu: mantém a ordem.
            $this->release(2);
        } elseif ($outcome === 'suppressed') {
            Log::info('WhatsApp AI reply suppressed: conversation taken over during reply delay.', [
                'message_id' => $this->messageId,
            ]);
        }
    }
}
