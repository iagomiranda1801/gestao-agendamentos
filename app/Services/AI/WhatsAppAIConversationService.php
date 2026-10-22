<?php

namespace App\Services\AI;

use App\Enums\WhatsAppOutboundKind;
use App\Jobs\SendWhatsAppAIReplyJob;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Models\WhatsAppContact;
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
        string $text, ?string $messageId, ?string $mediaMime, bool $paused = false, ?string $pushName = null): void
    {
        $phone = PhoneNormalizer::normalize($phone);
        if (! $phone || ! $messageId) {
            return;
        }
        $out = Cache::lock("wa:ai:{$company->id}:{$phone}", 150)->block(10, function () use ($company, $instance, $jid, $phone, $text, $messageId, $mediaMime, $paused, $pushName): ?TattooAiMessage {
            $conversation = TattooAiConversation::query()->firstOrCreate(
                ['company_whatsapp_instance_id' => $instance->id, 'phone_normalized' => $phone],
                ['company_id' => $company->id, 'remote_jid' => $jid, 'status' => 'collecting_information'],
            );
            $this->linkKnownClient($conversation);
            $this->rememberWhatsAppName($conversation, $pushName);
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
            $this->afterReplySent($conversation, $out);
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

    protected function afterReplySent(TattooAiConversation $conversation, TattooAiMessage $message): void {}

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
        $data = $conversation->collected_data ?: [];
        if (! $this->isPlaceholderName($client->name) && CustomerNameDetector::isValidStoredName($client->name)) {
            if ($this->canPrefillCollectedName($conversation, $client->name)) {
                $updates['collected_data'] = array_merge($data, ['name' => $client->name, 'name_source' => 'client']);
            } elseif (blank($data['_name_candidate'] ?? null) && empty($data['_name_candidate_rejected'])) {
                // Nome cadastrado que gera dúvida (ex.: igual ao de um profissional) é confirmado, não assumido.
                $updates['collected_data'] = $this->withNameCandidate($data, $client->name, 'client');
            }
        }
        if ((int) $conversation->client_id !== (int) $client->id || isset($updates['collected_data'])) {
            $conversation->update($updates);
        }
    }

    protected function saveClient(TattooAiConversation $conversation, string $name, ?string $source = null): Client
    {
        $source ??= $conversation->collected_data['name_source'] ?? 'customer';
        $client = Client::query()->where('company_id', $conversation->company_id)
            ->whereIn('phone_normalized', PhoneNormalizer::candidates($conversation->phone_normalized))->first();
        if (! $client) {
            $client = new Client(['name' => $name, 'phone' => $conversation->phone_normalized,
                'is_active' => true, 'source' => 'whatsapp']);
            $client->company_id = $conversation->company_id;
            $client->save();
        } elseif ($this->shouldReplaceClientName($client, $name)) {
            $previous = $client->name;
            $client->update(['name' => $name]);
            Log::info($this->logLabel().' client name corrected.', ['company_id' => $conversation->company_id,
                'conversation_id' => $conversation->id, 'client_id' => $client->id,
                'previous_name' => mb_substr((string) $previous, 0, 120), 'name_source' => $source]);
        }
        if ((int) $conversation->client_id !== (int) $client->id) {
            $conversation->update(['client_id' => $client->id]);
        }

        return $client;
    }

    /**
     * Só troca o nome do cadastro quando ele é provisório ("Cliente WhatsApp 1234")
     * ou é uma frase gravada pelo próprio WhatsApp (ex.: "queria fazer um
     * orçamento"). Nome válido ou cadastrado pela equipe nunca é sobrescrito.
     */
    protected function shouldReplaceClientName(Client $client, string $name): bool
    {
        if (! CustomerNameDetector::isValidStoredName($name) || $this->isPlaceholderName($name)) {
            return false;
        }

        return $this->isPlaceholderName((string) $client->name)
            || ($client->source === 'whatsapp' && ! CustomerNameDetector::isValidStoredName($client->name));
    }

    protected function isPlaceholderName(string $name): bool
    {
        return preg_match('/^(?:Cliente WhatsApp|Contato)\s*\d+$/iu', trim($name)) === 1
            || ctype_digit(trim($name));
    }

    protected function canPrefillCollectedName(TattooAiConversation $conversation, string $name): bool
    {
        return true;
    }

    /**
     * Guarda um nome sugerido pelo WhatsApp para a IA confirmar com o cliente.
     * Prioridade: nome da agenda do celular da empresa > nome do perfil
     * (pushName). Cadastro válido já entra antes, em linkKnownClient().
     */
    protected function rememberWhatsAppName(TattooAiConversation $conversation, ?string $pushName): void
    {
        $data = $conversation->collected_data ?: [];
        if (filled($data['name'] ?? null) || filled($data['_name_candidate'] ?? null) || ! empty($data['_name_candidate_rejected'])) {
            return;
        }
        $push = $this->cleanWhatsAppName($pushName);
        try {
            $book = $this->cleanWhatsAppName($this->contactBookName($conversation));
        } catch (Throwable $exception) {
            // A agenda é opcional: qualquer falha segue só com o pushName.
            Log::info($this->logLabel().' contact book lookup skipped.', ['company_id' => $conversation->company_id,
                'conversation_id' => $conversation->id, 'error_type' => $exception::class]);
            $book = null;
        }
        if ($book !== null && $push !== null && $this->normalizedText($book) === $this->normalizedText($push)) {
            // A sincronização grava o pushName quando não há nome na agenda: não dá pra afirmar que veio da agenda.
            $book = null;
        }
        [$candidate, $source] = match (true) {
            $book !== null => [$book, 'contact_book'],
            $push !== null => [$push, 'push_name'],
            default => [null, null],
        };
        if ($candidate === null) {
            return;
        }
        $conversation->update(['collected_data' => $this->withNameCandidate($data, $candidate, $source)]);
    }

    /**
     * Nome salvo no celular da empresa, vindo dos contatos já sincronizados
     * (findContacts). Consulta local e em cache: nunca chama a Evolution aqui.
     */
    protected function contactBookName(TattooAiConversation $conversation): ?string
    {
        $key = "wa:ai:contact-name:{$conversation->company_whatsapp_instance_id}:{$conversation->phone_normalized}";
        $name = Cache::remember($key, now()->addMinutes(30), function () use ($conversation): string {
            $contact = WhatsAppContact::query()
                ->where('company_id', $conversation->company_id)
                ->where('company_whatsapp_instance_id', $conversation->company_whatsapp_instance_id)
                ->whereIn('phone_normalized', PhoneNormalizer::candidates($conversation->phone_normalized))
                ->first();

            return (string) ($contact?->name ?? '');
        });

        return $name !== '' && PhoneNormalizer::normalize($name) !== PhoneNormalizer::normalize($conversation->phone_normalized)
            ? $name : null;
    }

    protected function cleanWhatsAppName(?string $name): ?string
    {
        $name = Str::squish((string) $name);
        if ($name === '' || $this->isPlaceholderName($name) || ! CustomerNameDetector::isPlausible($name)) {
            return null;
        }

        return $name === mb_strtolower($name) || $name === mb_strtoupper($name)
            ? mb_convert_case(mb_strtolower($name), MB_CASE_TITLE, 'UTF-8') : $name;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withNameCandidate(array $data, string $name, string $source): array
    {
        return array_merge($data, ['_name_candidate' => $name, '_name_candidate_source' => $source]);
    }

    /**
     * Em vez de pedir o nome, pergunta se o nome sugerido está certo
     * ("É Lucas mesmo?"). Null quando não há nome sugerido.
     */
    protected function nameConfirmationQuestion(TattooAiConversation $conversation): ?string
    {
        $data = $conversation->collected_data ?: [];
        if (filled($data['name'] ?? null) || blank($data['_name_candidate'] ?? null)) {
            return null;
        }
        $data['_name_confirm_pending'] = true;
        $conversation->update(['collected_data' => $data]);

        return 'É '.$this->displayFirstName((string) $data['_name_candidate']).' mesmo?';
    }

    protected function nameCandidateFirstName(TattooAiConversation $conversation): ?string
    {
        $candidate = $conversation->collected_data['_name_candidate'] ?? null;

        return filled($candidate) && blank($conversation->collected_data['name'] ?? null)
            ? $this->displayFirstName((string) $candidate) : null;
    }

    /**
     * Resposta do cliente à confirmação do nome. A correção do cliente sempre
     * prevalece; se ele ignorar a pergunta e seguir a conversa, o nome sugerido
     * é aceito e a mensagem segue o fluxo normal.
     *
     * @return 'confirmed'|'corrected'|'rejected'|'implicit'|null
     */
    protected function resolvePendingNameConfirmation(TattooAiConversation $conversation, string $text): ?string
    {
        $data = $conversation->collected_data ?: [];
        if (empty($data['_name_confirm_pending']) || filled($data['name'] ?? null) || blank($data['_name_candidate'] ?? null)) {
            return null;
        }
        $candidate = (string) $data['_name_candidate'];
        $source = (string) ($data['_name_candidate_source'] ?? 'push_name');
        unset($data['_name_confirm_pending']);
        $normalized = $this->normalizedText($text);
        $denied = (bool) preg_match('/^(?:nao|n|nops?|negativo|errado|errou)\b|\b(?:na verdade|nao sou|nao e (?:esse|meu nome))\b/u', $normalized);
        $stripped = trim((string) preg_replace('/^(?:n[aã]o|n|nops?|negativo|errado)\b[\s,!.]*|^(?:na verdade)[\s,]*/iu', '', trim($text)));
        $stripped = trim((string) preg_replace('/^(?:[eé]h?)\s+/iu', '', $stripped));
        $named = $stripped !== '' ? CustomerNameDetector::fromMessage($stripped) : null;

        $outcome = match (true) {
            ! $denied && (bool) preg_match('/^(?:sim|s|ss|isso|isso mesmo|isso ai|exato|exatamente|correto|certo|certinho|pode ser|pode|sou eu|sou|eu mesm[oa]|e sim|e isso|e|uhum|aham|claro|com certeza|yes|positivo|confirmo)(?:[\s,!.]+(?:sim|mesmo|isso|pode|sou))*[\s!.]*$/u', $normalized) => 'confirmed',
            $named !== null && $this->normalizedText($named) !== $this->normalizedText($candidate)
                && ($denied || ! $this->isGreeting($normalized)) => 'corrected',
            $denied => 'rejected',
            $named !== null => 'confirmed',
            default => 'implicit',
        };
        if ($outcome === 'rejected') {
            unset($data['_name_candidate'], $data['_name_candidate_source']);
            $data['_name_candidate_rejected'] = true;
        } else {
            $data['name'] = $outcome === 'corrected' ? $named : $candidate;
            $data['name_source'] = $outcome === 'corrected' ? 'customer' : $source;
            unset($data['_name_candidate'], $data['_name_candidate_source']);
        }
        $conversation->update(['collected_data' => $data]);
        Log::info($this->logLabel().' name confirmation.', ['company_id' => $conversation->company_id,
            'conversation_id' => $conversation->id, 'outcome' => $outcome, 'name_source' => $data['name_source'] ?? null]);

        return $outcome;
    }

    /**
     * Remove a saudação do começo de um texto livre do modelo ("Olá Lucas!
     * Tudo bem? ..."): depois da primeira mensagem, ninguém cumprimenta de novo.
     */
    protected function stripOpeningGreeting(string $reply): string
    {
        $pattern = '/^\s*(?:(?i:oi+e?|ol[aá]+|opa|e a[ií]|eae|eai|salve|hey|bom dia|boa tarde|boa noite|prazer)(?:[\s,]+\p{Lu}[\p{L}\'\-]*)?\s*[!,.]*\s*)?'
            .'(?:(?i:tudo (?:bem|bom|certo|tranquilo|joia|[oó]timo)|td bem|como vai)(?:\s+(?i:com|e)\s+(?i:voc[eê]|vc))?\s*\?+[\s!.]*)?/u';
        $clean = trim((string) preg_replace($pattern, '', $reply, 1));
        if ($clean === trim($reply) || $clean === '') {
            return $clean;
        }

        return mb_strtoupper(mb_substr($clean, 0, 1)).mb_substr($clean, 1);
    }
}
