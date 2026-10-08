<?php

namespace App\Services\AI;

use App\Enums\WhatsAppOutboundKind;
use App\Jobs\SendWhatsAppAIReplyJob;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundGate;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use App\Support\CustomerNameDetector;
use App\Support\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Núcleo comum do atendimento com IA no WhatsApp: guarda a conversa e as
 * mensagens, respeita a pausa quando a equipe assume, chama o Gemini e envia
 * a resposta pelo portão de saída. Cada segmento implementa apenas process().
 */
abstract class WhatsAppAIConversationService
{
    protected CompanyAIService $gemini;

    protected EvolutionApiClient $evolution;

    abstract protected function process(TattooAiConversation $conversation, TattooAiMessage $message): ?string;

    /**
     * Resposta determinística usada quando process() quebra: normalmente a
     * próxima pergunta do fluxo, montada só com o que já foi coletado.
     */
    abstract protected function fallbackReply(TattooAiConversation $conversation, TattooAiMessage $message): ?string;

    /** Último recurso quando nem a resposta de reserva funciona. */
    abstract protected function instabilityMessage(): string;

    /**
     * Inícios de resposta que avisam o cliente que a equipe foi chamada; eles
     * ainda são enviados mesmo com a conversa marcada como atendimento humano.
     *
     * @return list<string>
     */
    abstract protected function handoffPrefixes(): array;

    protected function logLabel(): string
    {
        return 'WhatsApp AI';
    }

    public function handle(Company $company, CompanyWhatsAppInstance $instance, string $jid, string $phone,
        string $text, ?string $messageId, ?string $mediaMime, bool $paused = false): void
    {
        $phone = PhoneNormalizer::normalize($phone);
        if (! $phone || ! $messageId) {
            return;
        }
        $out = Cache::lock("wa:ai:{$company->id}:{$phone}", 150)->block(10, function () use ($company, $instance, $jid, $phone, $text, $messageId, $mediaMime, $paused): ?TattooAiMessage {
            $conversation = TattooAiConversation::query()->firstOrCreate(
                ['company_whatsapp_instance_id' => $instance->id, 'phone_normalized' => $phone],
                ['company_id' => $company->id, 'remote_jid' => $jid, 'status' => 'collecting_information'],
            );
            $this->linkKnownClient($conversation);
            $incoming = TattooAiMessage::query()->firstOrCreate(
                ['company_id' => $company->id, 'provider_message_id' => $messageId],
                ['tattoo_ai_conversation_id' => $conversation->id, 'direction' => 'in',
                    'body' => mb_substr($text, 0, 4000), 'media_mime' => $mediaMime],
            );
            if ($incoming->tattoo_ai_conversation_id !== $conversation->id || $incoming->status === 'processed') {
                return null;
            }
            $conversation->update(['last_interaction_at' => now(), 'remote_jid' => $jid]);
            if ($paused || $conversation->human_takeover) {
                $incoming->update(['status' => 'processed']);

                return null;
            }
            $reply = $this->replyWithoutSilence($conversation, $incoming);
            $out = null;
            if ($reply !== null && trim($reply) !== '') {
                $out = $conversation->messages()->firstOrCreate(
                    ['provider_message_id' => 'reply:'.$messageId],
                    ['company_id' => $company->id, 'direction' => 'out', 'status' => 'pending', 'body' => mb_substr($reply, 0, 4000)],
                );
                if ($out->status !== 'pending' || $this->suppressIfTakenOver($conversation, $out)) {
                    $out = null;
                }
            }
            $incoming->update(['status' => 'processed']);

            return $out;
        });
        if ($out === null) {
            return;
        }

        // A entrega acontece fora da trava: com atraso humano vai pra fila
        // (sem prender o worker); com o atraso desligado sai na hora.
        $plan = $this->replyTiming($out);
        if ($plan === null) {
            if ($this->deliver($out->id) === 'wait') {
                SendWhatsAppAIReplyJob::dispatch(static::class, $out->id)->delay(now()->addSeconds(2));
            }

            return;
        }
        SendWhatsAppAIReplyJob::dispatch(static::class, $out->id, $plan['typing_ms'])->delay($plan['dispatch_at']);
    }

    /**
     * Entrega uma resposta pendente. Logo antes de enviar confere de novo se a
     * equipe assumiu ou pausou a conversa, se uma resposta anterior ainda não
     * saiu (mantém a ordem) e se é repetição da resposta que acabou de sair.
     *
     * @return string sent | skipped | suppressed | duplicate | wait
     */
    public function deliver(int $messageId, int $typingMs = 0): string
    {
        $out = TattooAiMessage::query()->with('conversation.instance', 'conversation.company')->find($messageId);
        if (! $out || $out->direction !== 'out' || $out->status !== 'pending' || ! $out->conversation?->instance) {
            return 'skipped';
        }
        $conversation = $out->conversation;
        $outcome = Cache::lock("wa:ai:{$conversation->company_id}:{$conversation->phone_normalized}", 150)
            ->block(10, function () use ($out, $conversation): string {
                $out->refresh();
                $conversation->refresh();
                if ($out->status !== 'pending') {
                    return 'skipped';
                }
                if ($this->suppressIfTakenOver($conversation, $out)) {
                    return 'suppressed';
                }
                $earlier = $conversation->messages()->where('direction', 'out')->where('id', '<', $out->id);
                if ((clone $earlier)->whereIn('status', ['pending', 'sending'])->where('updated_at', '>=', now()->subMinutes(2))->exists()) {
                    return 'wait';
                }
                $previous = (clone $earlier)->whereIn('status', ['sending', 'sent'])->latest('id')->first();
                $askedAt = TattooAiMessage::query()->where('company_id', $out->company_id)
                    ->where('provider_message_id', Str::after((string) $out->provider_message_id, 'reply:'))->value('created_at');
                if ($previous && $askedAt && trim((string) $previous->body) === trim((string) $out->body)
                    && $previous->updated_at?->gt($askedAt)) {
                    // O cliente mandou outra mensagem ("oi" e "olá") antes da resposta anterior sair:
                    // a mesma resposta não vai duas vezes.
                    $out->update(['status' => 'suppressed']);
                    Log::info($this->logLabel().' duplicate reply suppressed.', ['company_id' => $conversation->company_id,
                        'conversation_id' => $conversation->id, 'message_id' => $out->provider_message_id]);

                    return 'duplicate';
                }
                // Uma falha incerta do provedor fica para revisão humana; retentativas não podem enviar duas vezes.
                $out->update(['status' => 'sending']);

                return 'claimed';
            });
        if ($outcome !== 'claimed') {
            return $outcome;
        }

        $company = $conversation->company;
        $gate = app(WhatsAppOutboundGate::class);
        $gate->reserve($company, WhatsAppOutboundKind::BotReply);
        try {
            $instance = $conversation->instance->instance_name;
            $typingMs > 0
                ? $this->evolution->sendText($instance, $conversation->phone_normalized, (string) $out->body, $typingMs)
                : $this->evolution->sendText($instance, $conversation->phone_normalized, (string) $out->body);
            $gate->recordSuccess($company);
            $out->update(['status' => 'sent']);
        } catch (Throwable $exception) {
            $out->update(['status' => 'failed']);
            $gate->recordFailure($company);
            Log::warning($this->logLabel().' reply delivery failed.', [
                'company_id' => $company->id, 'conversation_id' => $conversation->id,
                'message_id' => $out->provider_message_id, 'error_type' => $exception::class,
            ]);
            throw $exception;
        }

        return 'sent';
    }

    /**
     * Atraso humano da resposta: entre min e max segundos contados da chegada
     * da mensagem do cliente (respostas longas puxam pro fim da faixa), com
     * "digitando..." nos últimos segundos. Respostas seguidas da mesma
     * conversa ficam em fila, espaçadas, na ordem em que foram geradas.
     *
     * @return array{dispatch_at: CarbonImmutable, typing_ms: int, send_at: CarbonImmutable}|null
     */
    protected function replyTiming(TattooAiMessage $out): ?array
    {
        $config = (array) config('services.evolution.ai_reply_delay', []);
        $max = max(0, (int) ($config['max_seconds'] ?? 0));
        if ($max === 0) {
            return null;
        }
        $min = max(0, min((int) ($config['min_seconds'] ?? 0), $max));
        $typingMax = max(0, (int) ($config['typing_max_seconds'] ?? 4));
        $floor = $min + intdiv(($max - $min) * min(mb_strlen((string) $out->body), 400), 800);
        $seconds = random_int($floor, $max);

        $now = CarbonImmutable::now();
        $incoming = TattooAiMessage::query()->where('company_id', $out->company_id)
            ->where('provider_message_id', Str::after((string) $out->provider_message_id, 'reply:'))->value('created_at');
        $receivedAt = $incoming ? CarbonImmutable::parse($incoming) : $now;
        $sendAt = $receivedAt->addSeconds($seconds);

        $key = "wa:ai:next-send:{$out->tattoo_ai_conversation_id}";
        $last = Cache::get($key);
        if (is_numeric($last)) {
            $sendAt = $sendAt->max(CarbonImmutable::createFromTimestamp((int) $last + min($typingMax, $seconds) + 2));
        }
        $sendAt = $sendAt->max($now);
        $remaining = (int) $now->diffInSeconds($sendAt, true);
        $typing = min($typingMax, $remaining);
        Cache::put($key, $sendAt->getTimestamp(), now()->addMinutes(10));

        return ['dispatch_at' => $now->addSeconds($remaining - $typing), 'typing_ms' => $typing * 1000, 'send_at' => $sendAt];
    }

    protected function suppressIfTakenOver(TattooAiConversation $conversation, TattooAiMessage $out): bool
    {
        $paused = app(WhatsAppHumanTakeover::class)->isPaused((string) $conversation->instance?->instance_name, $conversation->phone_normalized);
        if (! $paused && ! ($conversation->human_takeover && ! $this->isHandoffAcknowledgement((string) $out->body))) {
            return false;
        }
        $out->update(['status' => 'suppressed']);

        return true;
    }

    /**
     * Nenhuma mensagem fica sem resposta: se algo inesperado acontecer (Gemini
     * fora do ar, cota esgotada, JSON estranho, erro interno), o cliente recebe
     * a próxima pergunta do fluxo e o motivo fica registrado no log.
     */
    protected function replyWithoutSilence(TattooAiConversation $conversation, TattooAiMessage $message): ?string
    {
        try {
            return $this->process($conversation, $message);
        } catch (Throwable $exception) {
            $this->logFallback($conversation, $message, 'unexpected_error', $exception);
        }
        try {
            return $this->fallbackReply($conversation->fresh() ?? $conversation, $message);
        } catch (Throwable $exception) {
            $this->logFallback($conversation, $message, 'fallback_failed', $exception);

            return $this->instabilityMessage();
        }
    }

    /**
     * Chama o Gemini; qualquer falha (HTTP, cota, timeout, JSON inválido) vira
     * null para o fluxo seguir sem a IA.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function interpret(TattooAiConversation $conversation, TattooAiMessage $message, string $instruction, array $payload): ?array
    {
        try {
            return $this->askModel($conversation, $message, $instruction, $payload);
        } catch (Throwable $exception) {
            $this->logFallback($conversation, $message, 'model_unavailable', $exception);

            return null;
        }
    }

    protected function logFallback(TattooAiConversation $conversation, TattooAiMessage $message, string $reason, ?Throwable $exception = null): void
    {
        Log::warning($this->logLabel().' fallback reply.', [
            'company_id' => $conversation->company_id, 'conversation_id' => $conversation->id,
            'message_id' => $message->provider_message_id, 'reason' => $reason,
            'error_type' => $exception ? $exception::class : null,
            'http_status' => $exception instanceof RequestException ? $exception->response->status() : null,
            'error' => $exception ? mb_substr($exception->getMessage(), 0, 300) : null,
        ]);
    }

    protected function normalizedText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', Str::lower(Str::ascii($text))));
    }

    /** "Oi", "Ola", "bom dia", "e aí, tudo bem?"... (texto já normalizado). */
    protected function isGreeting(string $normalized): bool
    {
        return CustomerNameDetector::isGreeting($normalized)
            || (bool) preg_match('/^(?:oi+e?|ola+|opa|oie|hey|hello|salve|e ai|eai|eae|bom dia|boa tarde|boa noite)(?:[,!. ]+(?:moca|moco|gente|pessoal|tudo bem|td bem|tudo bom|como vai))*[!.?, ]*$/u', $normalized);
    }

    /** "bem e vc?", "tudo bem?", "tô bem, e você?"... (texto já normalizado). */
    protected function isSmallTalk(string $normalized): bool
    {
        $you = '(?:voce|vc|vcs|voces|ai|contigo)';

        return (bool) preg_match('/^(?:(?:oi+|ola)[,! ]+)?(?:'
            .'(?:e )?(?:tudo (?:bem|bom|certo|joia|otimo|tranquilo)|td (?:bem|bom)|como vai|como (?:voce|vc) (?:esta|ta))(?: (?:com|e) '.$you.')?'
            .'|(?:(?:eu )?(?:to|tou|estou|ta) )?(?:bem|otimo|otima|tranquilo|tranquila|tudo (?:bem|bom|certo|otimo|joia|sim)|mais ou menos)(?: (?:gracas a deus|obrigad[oa]))?(?:,? (?:e )?(?:com )?'.$you.')?'
            .'|e (?:com )?'.$you
            .')[\s!.?,]*$/u', $normalized);
    }

    /** Nome cadastrado todo em maiúsculas/minúsculas aparece como "Iago". */
    protected function displayFirstName(string $name): string
    {
        $first = $this->firstName($name);

        return $first === mb_strtoupper($first) || $first === mb_strtolower($first)
            ? mb_convert_case(mb_strtolower($first), MB_CASE_TITLE, 'UTF-8') : $first;
    }

    protected function isHandoffAcknowledgement(string $body): bool
    {
        foreach ($this->handoffPrefixes() as $prefix) {
            if (str_starts_with($body, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chama o Gemini com o protocolo JSON e registra o consumo de tokens na mensagem.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function askModel(TattooAiConversation $conversation, TattooAiMessage $message, string $instruction, array $payload): array
    {
        $result = $this->gemini->structured($instruction, json_encode($payload,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), context: [
                'company_id' => $conversation->company_id, 'conversation_id' => $conversation->id,
                'message_id' => $message->provider_message_id,
            ]);
        $usage = $result['_usage'] ?? [];
        $message->update(['input_tokens' => $usage['input'] ?? null, 'output_tokens' => $usage['output'] ?? null]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $allowed
     */
    protected function allowedAction(TattooAiConversation $conversation, array $result, array $allowed): ?string
    {
        $action = $result['action'] ?? 'ask';
        if (is_string($action) && in_array($action, $allowed, true)) {
            return $action;
        }
        Log::warning($this->logLabel().' rejected unknown action.', ['company_id' => $conversation->company_id,
            'conversation_id' => $conversation->id, 'action' => is_scalar($action) ? $action : 'invalid']);

        return null;
    }

    /**
     * Texto livre do modelo que cita preço, horário ou confirmação nunca vai
     * para o cliente: esses dados só saem do sistema.
     */
    protected function mentionsUnverifiedFacts(string $reply): bool
    {
        return (bool) preg_match('/R\$\s*\d|\b\d+(?:[.,]\d+)?\s*(?:reais|real)\b|\b(pagamento|sinal)\s+(foi\s+)?confirmado\b|\b\d{1,2}(?::\d{2}|h\d{0,2})\b/iu', $reply);
    }

    protected function firstName(string $name): string
    {
        return explode(' ', trim($name))[0] ?: trim($name);
    }

    protected function linkKnownClient(TattooAiConversation $conversation): void
    {
        if (filled($conversation->collected_data['name'] ?? null)) {
            return;
        }

        $client = $conversation->client_id
            ? Client::query()->where('company_id', $conversation->company_id)->find($conversation->client_id)
            : null;
        $client ??= Client::query()->where('company_id', $conversation->company_id)
            ->whereIn('phone_normalized', PhoneNormalizer::candidates($conversation->phone_normalized))->first();
        if (! $client) {
            return;
        }

        $updates = ['client_id' => $client->id];
        if (! $this->isPlaceholderName($client->name)) {
            $updates['collected_data'] = array_merge($conversation->collected_data ?: [], ['name' => $client->name]);
        }
        if ((int) $conversation->client_id !== (int) $client->id || isset($updates['collected_data'])) {
            $conversation->update($updates);
        }
    }

    protected function saveClient(TattooAiConversation $conversation, string $name): Client
    {
        $client = Client::query()->where('company_id', $conversation->company_id)
            ->whereIn('phone_normalized', PhoneNormalizer::candidates($conversation->phone_normalized))->first();
        if (! $client) {
            $client = new Client(['name' => $name, 'phone' => $conversation->phone_normalized,
                'is_active' => true, 'source' => 'whatsapp']);
            $client->company_id = $conversation->company_id;
            $client->save();
        } elseif ($this->isPlaceholderName($client->name)) {
            $client->update(['name' => $name]);
        }
        if ((int) $conversation->client_id !== (int) $client->id) {
            $conversation->update(['client_id' => $client->id]);
        }

        return $client;
    }

    protected function isPlaceholderName(string $name): bool
    {
        return preg_match('/^(?:Cliente WhatsApp|Contato)\s*\d+$/iu', trim($name)) === 1
            || ctype_digit(trim($name));
    }
}
