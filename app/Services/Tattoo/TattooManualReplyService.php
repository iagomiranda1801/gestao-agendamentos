<?php

namespace App\Services\Tattoo;

use App\Enums\WhatsAppOutboundKind;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundGate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TattooManualReplyService
{
    public function __construct(
        protected EvolutionApiClient $evolution,
        protected WhatsAppOutboundGate $outbound,
    ) {}

    public function send(TattooAiConversation $conversation, string $body): TattooAiMessage
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 4000) {
            throw ValidationException::withMessages(['messageDraft' => 'Escreva uma mensagem de até 4.000 caracteres.']);
        }

        return Cache::lock("wa:ai:{$conversation->company_id}:{$conversation->phone_normalized}", 150)
            ->block(10, function () use ($conversation, $body): TattooAiMessage {
                $conversation->refresh();
                if (! $conversation->human_takeover) {
                    throw ValidationException::withMessages(['messageDraft' => 'Assuma o atendimento antes de responder.']);
                }

                $message = $conversation->messages()->create([
                    'company_id' => $conversation->company_id,
                    'provider_message_id' => 'manual:'.Str::uuid(),
                    'direction' => 'out',
                    'status' => 'sending_manual',
                    'body' => $body,
                ]);

                try {
                    $this->outbound->reserve($conversation->company, WhatsAppOutboundKind::BotReply);
                    $this->evolution->sendText($conversation->instance->instance_name, $conversation->phone_normalized, $body);
                    $this->outbound->recordSuccess($conversation->company);
                    $message->update(['status' => 'sent_manual']);
                } catch (\Throwable $exception) {
                    $this->outbound->recordFailure($conversation->company);
                    $message->update(['status' => 'failed_manual']);
                    throw $exception;
                }

                $conversation->update(['last_interaction_at' => now()]);

                return $message;
            });
    }
}
