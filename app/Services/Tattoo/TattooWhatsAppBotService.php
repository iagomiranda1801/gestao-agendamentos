<?php

namespace App\Services\Tattoo;

use App\Enums\WhatsAppBotConversationState as State;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooRequest;
use App\Models\WhatsAppBotConversation;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Support\PhoneNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class TattooWhatsAppBotService
{
    public function __construct(
        protected EvolutionApiClient $evolution,
        protected TattooImageService $images,
    ) {}

    public function handleIncoming(
        Company $company,
        ?CompanyWhatsAppInstance $instance,
        string $remoteJid,
        string $rawPhone,
        string $text,
        ?string $messageId,
        ?string $imageMime,
    ): ?string {
        $phone = PhoneNormalizer::normalize($rawPhone);
        if ($phone === null || $instance === null) {
            return null;
        }

        $lock = Cache::lock("wa:bot:{$company->id}:{$phone}", 20);
        try {
            $lock->block(5);

            return $this->process($company, $instance, $remoteJid, $phone, trim($text), $messageId, $imageMime);
        } finally {
            $lock->release();
        }
    }

    protected function process(
        Company $company,
        CompanyWhatsAppInstance $instance,
        string $remoteJid,
        string $phone,
        string $text,
        ?string $messageId,
        ?string $imageMime,
    ): ?string {
        $conversation = WhatsAppBotConversation::query()
            ->where('company_id', $company->id)->where('phone_normalized', $phone)
            ->whereNull('finished_at')->latest('id')->first();
        if ($conversation && $conversation->expires_at?->isPast()) {
            $conversation->update(['finished_at' => now(), 'finished_reason' => 'expired']);
            $conversation = null;
        }
        if ($conversation && $messageId && $conversation->last_incoming_message_id === $messageId) {
            return null;
        }
        if ($conversation === null && $messageId && WhatsAppBotConversation::query()
            ->where('company_id', $company->id)->where('phone_normalized', $phone)
            ->where('last_incoming_message_id', $messageId)->exists()) {
            return null;
        }

        $normalized = Str::lower(Str::ascii($text));
        $wantsHuman = (bool) preg_match('/^(quero |gostaria de |preciso )?(atendente|humano|falar com (um |uma |a )?(atendente|pessoa|equipe))[!. ]*$/u', $normalized);
        $wantsBooking = (bool) preg_match('/\b(agendar|agendamento|marcar horario)\b/u', $normalized);
        $bookingCommand = (bool) preg_match('/^(quero |gostaria de |preciso )?(agendar|agendamento|marcar)( um)?( horario)?[!. ]*$/u', $normalized);
        if ($conversation === null) {
            $isGreeting = $this->isGreeting($normalized);
            $wantsQuote = (bool) preg_match('/\b(orcamento|tatuagem|tatuar|tattoo)\b/u', $normalized);
            if ($normalized === '') {
                return null;
            }
            $priorConversation = WhatsAppBotConversation::query()
                ->where('company_id', $company->id)->where('phone_normalized', $phone)
                ->latest('id')->first();
            if ($priorConversation !== null && ! $isGreeting && ! $wantsQuote && ! $wantsBooking && ! $wantsHuman && $normalized !== 'menu') {
                return null;
            }
            if ($isGreeting
                && $priorConversation?->finished_at?->gte(now()->subMinutes(15))) {
                return null;
            }
            $conversation = new WhatsAppBotConversation([
                'company_id' => $company->id,
                'company_whatsapp_instance_id' => $instance->id,
                'phone_normalized' => $phone,
                'remote_jid' => $remoteJid,
                'state' => State::TattooName,
                'data' => [],
            ]);
            $conversation->save();

            if ($wantsHuman) {
                $conversation->finished_at = now();
                $conversation->finished_reason = 'handoff';

                return $this->reply($conversation, $messageId, 'Certo, vou parar as perguntas. A equipe pode continuar com você por aqui.');
            }

            if ($wantsBooking) {
                return $this->reply($conversation, $messageId, $this->quoteBeforeBookingPrompt(State::TattooName));
            }

            if ($wantsQuote) {
                $description = $this->initialDescription($text);
                $conversation->data = $description === null ? [] : ['description' => $description];

                return $this->reply($conversation, $messageId, $description === null
                    ? 'Claro, vamos preparar seu pedido para o tatuador. Como posso te chamar?'
                    : 'Entendi a ideia da tatuagem. Como posso te chamar?');
            }

            return $this->reply($conversation, $messageId, "Oi! Você está falando com *{$company->name}*. Vou reunir sua ideia para o tatuador preparar um orçamento. Como posso te chamar?");
        }

        if ($normalized === 'menu') {
            $this->cancelDraft($conversation);
            $conversation->state = State::TattooName;
            $conversation->data = [];

            return $this->reply($conversation, $messageId, 'Vamos começar de novo. Como posso te chamar?');
        }

        $data = $conversation->data ?: [];
        $state = $conversation->state;
        if ($wantsHuman || ($text === '0' && $state !== State::TattooPhoto)) {
            $this->cancelDraft($conversation);
            $conversation->finished_at = now();
            $conversation->finished_reason = 'handoff';

            return $this->reply($conversation, $messageId, 'Certo, vou parar as perguntas. A equipe pode continuar com você por aqui.');
        }
        if ($bookingCommand || ($text === '2' && $state === State::Greeting)) {
            if ($state === State::Greeting) {
                $conversation->state = State::TattooName;
                $state = State::TattooName;
            }

            return $this->reply($conversation, $messageId, $this->quoteBeforeBookingPrompt($state));
        }
        if ($imageMime !== null && $state !== State::TattooPhoto) {
            return $this->reply($conversation, $messageId, 'Vou pedir a foto de referência após as informações sobre o desenho, local e tamanho.');
        }
        if ($state === State::Greeting) {
            if ($text !== '1' && preg_match('/\b(orcamento|tatuagem|tatuar|tattoo)\b/u', $normalized)) {
                $description = $this->initialDescription($text);
                $conversation->state = State::TattooName;
                $conversation->data = $description === null ? [] : ['description' => $description];

                return $this->reply($conversation, $messageId, 'Claro, vamos preparar seu pedido para o tatuador. Como posso te chamar?');
            }
            $conversation->state = State::TattooName;
            if ($text === '1') {
                return $this->reply($conversation, $messageId, 'Claro. Como posso te chamar?');
            }
            $state = State::TattooName;
        }

        if ($state === State::TattooName) {
            if (mb_strlen($text) < 2 || mb_strlen($text) > 120 || $this->isGreeting($normalized)) {
                return $this->reply($conversation, $messageId, 'Como posso te chamar? Pode ser só seu primeiro nome.');
            }
            $data['name'] = $text;
            $conversation->state = State::TattooDescription;
            $conversation->data = $data;

            if (isset($data['description'])) {
                $conversation->state = State::TattooPlacement;

                return $this->reply($conversation, $messageId, "Prazer, {$text}! Em que parte do corpo você pensa em fazer essa tatuagem?");
            }

            return $this->reply($conversation, $messageId, "Prazer, {$text}! Me conta como você imagina a tatuagem. Pode falar do desenho, estilo e cores do seu jeito.");
        }
        if ($state === State::TattooDescription) {
            if (mb_strlen($text) < 5 || mb_strlen($text) > 3000) {
                return $this->reply($conversation, $messageId, 'Descreva o desenho em pelo menos 5 caracteres.');
            }
            $data['description'] = $text;
            $conversation->state = State::TattooPlacement;
            $conversation->data = $data;

            return $this->reply($conversation, $messageId, 'Entendi. Em que parte do corpo você pensa em fazer essa tatuagem?');
        }
        if ($state === State::TattooPlacement) {
            if (mb_strlen($text) < 2 || mb_strlen($text) > 255) {
                return $this->reply($conversation, $messageId, 'Informe o local do corpo.');
            }
            $data['body_placement'] = $text;
            $conversation->state = State::TattooSize;
            $conversation->data = $data;

            return $this->reply($conversation, $messageId, 'E qual tamanho você imagina, mais ou menos? Pode ser uma estimativa, como 10 x 15 cm.');
        }
        if ($state === State::TattooSize) {
            if (mb_strlen($text) < 1 || mb_strlen($text) > 255) {
                return $this->reply($conversation, $messageId, 'Informe um tamanho aproximado.');
            }
            $data['size_description'] = $text;
            $request = DB::transaction(function () use ($company, $phone, $data, $conversation): TattooRequest {
                $client = Client::query()->where('company_id', $company->id)
                    ->whereIn('phone_normalized', PhoneNormalizer::candidates($phone))->first();
                if (! $client) {
                    $client = new Client(['name' => $data['name'], 'phone' => $phone, 'is_active' => true, 'source' => 'whatsapp']);
                    $client->company_id = $company->id;
                    $client->save();
                }
                $request = new TattooRequest([
                    'client_id' => $client->id, 'description' => $data['description'],
                    'body_placement' => $data['body_placement'], 'size_description' => $data['size_description'],
                    'source' => 'whatsapp', 'status' => 'collecting',
                    'whatsapp_bot_conversation_id' => $conversation->id,
                ]);
                $request->company_id = $company->id;
                $request->save();

                return $request;
            });
            $data['request_id'] = $request->id;
            $conversation->state = State::TattooPhoto;
            $conversation->data = $data;

            return $this->reply($conversation, $messageId, 'Tem alguma foto de referência? Pode mandar por aqui. Se não tiver, é só dizer *sem foto*.');
        }
        if ($state === State::TattooPhoto) {
            if ($imageMime !== null && $messageId) {
                $request = TattooRequest::query()->where('company_id', $company->id)->where('status', 'collecting')->findOrFail($data['request_id']);
                try {
                    $base64 = $this->evolution->getMediaBase64($instance->instance_name, $messageId);
                    if (strlen($base64) > 14 * 1024 * 1024) {
                        throw new \RuntimeException('Imagem muito grande.');
                    }
                    $binary = base64_decode($base64, true);
                    if ($binary === false) {
                        throw new \RuntimeException('Imagem inválida.');
                    }
                    $tmp = tempnam(sys_get_temp_dir(), 'tattoo_');
                    file_put_contents($tmp, $binary);
                    try {
                        $extension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$imageMime] ?? 'img';
                        $file = new UploadedFile($tmp, 'referencia.'.$extension, $imageMime, null, true);
                        $this->images->upload($request, $file, $messageId);
                    } finally {
                        @unlink($tmp);
                    }
                } catch (Throwable $exception) {
                    report($exception);

                    return $this->reply($conversation, $messageId, 'Não consegui salvar a imagem. Pode enviar de novo ou dizer *sem foto* para continuar.');
                }
                $conversation->state = State::TattooConfirm;

                return $this->reply($conversation, $messageId, $this->summary($data));
            }
            if (! preg_match('/^(0|nao tenho( foto)?|sem foto|pode seguir( sem foto)?|seguir sem foto)[!. ]*$/u', $normalized)) {
                return $this->reply($conversation, $messageId, 'Pode mandar uma foto de referência ou me dizer *sem foto* para continuar.');
            }
            $conversation->state = State::TattooConfirm;

            return $this->reply($conversation, $messageId, $this->summary($data));
        }
        if ($state === State::TattooConfirm) {
            if (in_array($normalized, ['1', 'sim', 'isso', 'esta certo', 'pode enviar', 'confirmar', 'confirmo'], true)) {
                TattooRequest::query()->where('company_id', $company->id)->where('status', 'collecting')->whereKey($data['request_id'])->update(['status' => 'awaiting_review']);
                $conversation->state = State::Done;
                $conversation->finished_at = now();
                $conversation->finished_reason = 'tattoo_request';

                return $this->reply($conversation, $messageId, 'Recebi seu pedido! A equipe vai analisar a ideia e preparar um orçamento para você.');
            }
            if (in_array($normalized, ['2', 'cancelar', 'cancela'], true)) {
                $this->cancelDraft($conversation);
                $conversation->finished_at = now();
                $conversation->finished_reason = 'cancelled';

                return $this->reply($conversation, $messageId, 'Tudo bem, cancelei o pedido. Quando quiser começar outro, diga *orçamento*.');
            }

            return $this->reply($conversation, $messageId, $this->summary($data));
        }

        return null;
    }

    protected function summary(array $data): string
    {
        return "Anotei assim:\nDesenho: {$data['description']}\nLocal: {$data['body_placement']}\nTamanho: {$data['size_description']}\n\nEstá tudo certo? Se estiver, me diga *sim* e eu envio para análise. Se quiser parar, diga *cancelar*.";
    }

    protected function quoteBeforeBookingPrompt(State $state): string
    {
        $intro = 'Por enquanto, começamos pelo pedido de orçamento. O tatuador analisa a ideia antes de combinar um horário.';

        return $intro.' '.match ($state) {
            State::TattooDescription => 'Como você imagina a tatuagem?',
            State::TattooPlacement => 'Em que parte do corpo você pensa em fazer?',
            State::TattooSize => 'Qual tamanho você imagina, mais ou menos?',
            State::TattooPhoto => 'Tem uma foto de referência? Se não tiver, diga *sem foto*.',
            State::TattooConfirm => 'Se as informações estiverem certas, diga *sim* para enviar o pedido.',
            default => 'Como posso te chamar?',
        };
    }

    protected function isGreeting(string $normalized): bool
    {
        return (bool) preg_match('/^(oi+|ola|opa|e ai|eae|bom dia|boa tarde|boa noite|tudo bem)(,? tudo bem)?[!.? ]*$/u', $normalized);
    }

    protected function initialDescription(string $text): ?string
    {
        $normalized = Str::lower(Str::ascii(trim($text)));
        if (mb_strlen($text) < 18 || mb_strlen($text) > 3000
            || ! preg_match('/\b(tatuagem|tattoo)\s+(de|com)\s+\S.{4,}|\btatuar\s+(um|uma|o|a)\s+\S.{4,}/u', $normalized)) {
            return null;
        }

        return trim($text);
    }

    protected function cancelDraft(WhatsAppBotConversation $conversation): void
    {
        $id = $conversation->data['request_id'] ?? null;
        if ($id) {
            TattooRequest::query()->where('company_id', $conversation->company_id)->where('status', 'collecting')->whereKey($id)->update(['status' => 'cancelled']);
        }
    }

    protected function reply(WhatsAppBotConversation $conversation, ?string $messageId, string $reply): string
    {
        $conversation->last_incoming_message_id = $messageId ?: $conversation->last_incoming_message_id;
        $conversation->last_activity_at = now();
        $conversation->expires_at = now()->addMinutes(30);
        $conversation->save();

        return $reply;
    }
}
