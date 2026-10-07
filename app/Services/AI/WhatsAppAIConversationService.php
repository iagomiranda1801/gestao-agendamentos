<?php

namespace App\Services\AI;

use App\Enums\WhatsAppOutboundKind;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooAiConversation;
use App\Models\TattooAiMessage;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundGate;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Núcleo comum do atendimento com IA no WhatsApp: guarda a conversa e as
 * mensagens, respeita a pausa quando a equipe assume, chama o Gemini e envia
 * a resposta pelo portão de saída. Cada segmento implementa apenas process().
 */
abstract class WhatsAppAIConversationService
{
    protected GeminiService $gemini;

    protected EvolutionApiClient $evolution;

    abstract protected function process(TattooAiConversation $conversation, TattooAiMessage $message): ?string;

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
        Cache::lock("wa:ai:{$company->id}:{$phone}", 150)->block(10, function () use ($company, $instance, $jid, $phone, $text, $messageId, $mediaMime, $paused): void {
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
                return;
            }
            $conversation->update(['last_interaction_at' => now(), 'remote_jid' => $jid]);
            if ($paused || $conversation->human_takeover) {
                $incoming->update(['status' => 'processed']);

                return;
            }
            $reply = $this->process($conversation, $incoming);
            if ($reply !== null && trim($reply) !== '') {
                $out = $conversation->messages()->firstOrCreate(
                    ['provider_message_id' => 'reply:'.$messageId],
                    ['company_id' => $company->id, 'direction' => 'out', 'status' => 'pending', 'body' => mb_substr($reply, 0, 4000)],
                );
                if ($out->status === 'pending') {
                    if (($conversation->fresh()->human_takeover && ! $this->isHandoffAcknowledgement((string) $out->body))
                        || app(WhatsAppHumanTakeover::class)->isPaused($instance->instance_name, $phone)) {
                        $out->update(['status' => 'suppressed']);
                        $incoming->update(['status' => 'processed']);

                        return;
                    }
                    app(WhatsAppOutboundGate::class)->reserve($company, WhatsAppOutboundKind::BotReply);
                    // An uncertain provider failure is left for human review; retries must not send twice.
                    $out->update(['status' => 'sending']);
                    try {
                        $this->evolution->sendText($instance->instance_name, $phone, $out->body);
                        app(WhatsAppOutboundGate::class)->recordSuccess($company);
                        $out->update(['status' => 'sent']);
                    } catch (\Throwable $exception) {
                        $out->update(['status' => 'failed']);
                        app(WhatsAppOutboundGate::class)->recordFailure($company);
                        Log::warning($this->logLabel().' reply delivery failed.', [
                            'company_id' => $company->id, 'conversation_id' => $conversation->id,
                            'message_id' => $messageId, 'error_type' => $exception::class,
                        ]);
                        throw $exception;
                    }
                }
            }
            $incoming->update(['status' => 'processed']);
        });
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
