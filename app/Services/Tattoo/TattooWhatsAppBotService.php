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

        $normalized = Str::lower(Str::ascii($text));
        if ($conversation === null) {
            if (! in_array($normalized, ['oi', 'ola', 'menu', 'orcamento', 'orçamento', 'tatuagem', 'agendar'], true)) {
                return null;
            }
            if (in_array($normalized, ['oi', 'ola'], true)
                && WhatsAppBotConversation::query()->where('company_id', $company->id)
                    ->where('phone_normalized', $phone)->whereNotNull('finished_at')
                    ->where('finished_at', '>=', now()->subMinutes(15))->exists()) {
                return null;
            }
            $conversation = new WhatsAppBotConversation([
                'company_id' => $company->id,
                'company_whatsapp_instance_id' => $instance->id,
                'phone_normalized' => $phone,
                'remote_jid' => $remoteJid,
                'state' => State::Greeting,
                'data' => [],
            ]);
            $conversation->save();

            return $this->reply($conversation, $messageId, "Olá! Você está falando com *{$company->name}*.\n1 - Pedir orçamento de tatuagem\n2 - Agendar um horário\n0 - Falar com a equipe");
        }

        if ($normalized === 'menu') {
            $this->cancelDraft($conversation);
            $conversation->state = State::Greeting;
            $conversation->data = [];

            return $this->reply($conversation, $messageId, "O que deseja fazer?\n1 - Pedir orçamento\n2 - Agendar\n0 - Falar com a equipe");
        }

        $data = $conversation->data ?: [];
        $state = $conversation->state;
        if ($imageMime !== null && $state !== State::TattooPhoto) {
            return $this->reply($conversation, $messageId, 'Vou pedir a foto de referência após as informações sobre o desenho, local e tamanho.');
        }
        if ($state === State::Greeting) {
            if ($text === '0') {
                $conversation->finished_at = now();
                $conversation->finished_reason = 'handoff';

                return $this->reply($conversation, $messageId, 'Certo. A equipe vai continuar seu atendimento por aqui.');
            }
            if ($text === '2') {
                $conversation->finished_at = now();
                $conversation->finished_reason = 'booking_link';
                $bookingEnabled = (bool) $company->schedulingSetting?->public_booking_enabled;

                return $this->reply($conversation, $messageId, $bookingEnabled
                    ? 'Você pode escolher um horário em '.route('public.booking.show', ['company' => $company])
                    : 'A equipe vai ajudar você a escolher um horário por aqui.');
            }
            if ($text !== '1') {
                return null;
            }
            $conversation->state = State::TattooName;

            return $this->reply($conversation, $messageId, 'Qual é seu nome?');
        }

        if ($state === State::TattooName) {
            if (mb_strlen($text) < 2 || mb_strlen($text) > 120) {
                return $this->reply($conversation, $messageId, 'Informe seu nome (de 2 a 120 caracteres).');
            }
            $data['name'] = $text;
            $conversation->state = State::TattooDescription;
            $conversation->data = $data;

            return $this->reply($conversation, $messageId, 'Conte qual desenho você quer tatuar. Pode descrever estilo e cores.');
        }
        if ($state === State::TattooDescription) {
            if (mb_strlen($text) < 5 || mb_strlen($text) > 3000) {
                return $this->reply($conversation, $messageId, 'Descreva o desenho em pelo menos 5 caracteres.');
            }
            $data['description'] = $text;
            $conversation->state = State::TattooPlacement;
            $conversation->data = $data;

            return $this->reply($conversation, $messageId, 'Em qual parte do corpo será a tatuagem?');
        }
        if ($state === State::TattooPlacement) {
            if (mb_strlen($text) < 2 || mb_strlen($text) > 255) {
                return $this->reply($conversation, $messageId, 'Informe o local do corpo.');
            }
            $data['body_placement'] = $text;
            $conversation->state = State::TattooSize;
            $conversation->data = $data;

            return $this->reply($conversation, $messageId, 'Qual o tamanho aproximado em centímetros? Pode responder, por exemplo, 10 x 15 cm.');
        }
        if ($state === State::TattooSize) {
            if (mb_strlen($text) < 1 || mb_strlen($text) > 255) {
                return $this->reply($conversation, $messageId, 'Informe um tamanho aproximado.');
            }
            $data['size_description'] = $text;
            $request = DB::transaction(function () use ($company, $phone, $data, $conversation): TattooRequest {
                $client = Client::query()->where('company_id', $company->id)->where('phone_normalized', $phone)->first();
                if (! $client) {
                    $client = new Client(['name' => $data['name'], 'phone' => $phone, 'is_active' => true]);
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

            return $this->reply($conversation, $messageId, 'Envie uma foto de referência (JPEG, PNG ou WebP), ou digite *0* se não tiver foto.');
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
                        $file = new UploadedFile($tmp, 'referencia.jpg', $imageMime, null, true);
                        $this->images->upload($request, $file, $messageId);
                    } finally {
                        @unlink($tmp);
                    }
                } catch (Throwable $exception) {
                    report($exception);

                    return $this->reply($conversation, $messageId, 'Não consegui salvar a foto. Envie novamente ou digite *0* para continuar sem foto.');
                }
                $conversation->state = State::TattooConfirm;

                return $this->reply($conversation, $messageId, $this->summary($data));
            }
            if ($text !== '0') {
                return $this->reply($conversation, $messageId, 'Envie uma foto ou digite *0* para continuar sem foto.');
            }
            $conversation->state = State::TattooConfirm;

            return $this->reply($conversation, $messageId, $this->summary($data));
        }
        if ($state === State::TattooConfirm) {
            if ($text === '1') {
                TattooRequest::query()->where('company_id', $company->id)->where('status', 'collecting')->whereKey($data['request_id'])->update(['status' => 'awaiting_review']);
                $conversation->state = State::Done;
                $conversation->finished_at = now();
                $conversation->finished_reason = 'tattoo_request';

                return $this->reply($conversation, $messageId, 'Pedido recebido! O tatuador vai analisar as informações e responder com o orçamento por aqui.');
            }
            if ($text === '2') {
                $this->cancelDraft($conversation);
                $conversation->finished_at = now();
                $conversation->finished_reason = 'cancelled';

                return $this->reply($conversation, $messageId, 'Pedido cancelado. Envie *menu* para começar novamente.');
            }

            return $this->reply($conversation, $messageId, $this->summary($data));
        }

        return null;
    }

    protected function summary(array $data): string
    {
        return "Confirma o pedido?\nDesenho: {$data['description']}\nLocal: {$data['body_placement']}\nTamanho: {$data['size_description']}\n1 - Confirmar\n2 - Cancelar";
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
