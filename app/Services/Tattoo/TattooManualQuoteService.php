<?php

namespace App\Services\Tattoo;

use App\Models\Client;
use App\Models\Company;
use App\Models\CompanySchedulingSetting;
use App\Models\CompanyWhatsAppInstance;
use App\Models\TattooAiConversation;
use App\Models\TattooManualQuoteDetection;
use App\Models\TattooPaymentReceipt;
use App\Models\TattooQuote;
use App\Models\TattooRequest;
use App\Services\Client\ClientService;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orçamento digitado à mão pelo tatuador no WhatsApp do estúdio: detecta o
 * valor, pergunta no WhatsApp pessoal do tatuador se deve salvar e, no "1",
 * grava cliente + pedido + orçamento (já enviado) sem reenviar ao cliente.
 */
class TattooManualQuoteService
{
    public function __construct(protected ManualQuoteDetector $detector) {}

    /** O número pessoal do tatuador nunca é tratado como cliente. */
    public function isArtistPhone(string $instanceName, string $phone): bool
    {
        [, $setting] = $this->context($instanceName);

        return $setting !== null && $this->matchesArtist($setting, $phone);
    }

    /** Mensagem manual (fromMe) do estúdio para um cliente. */
    public function detectFromOutbound(string $instanceName, string $phone, string $text, ?string $messageId): ?TattooManualQuoteDetection
    {
        [$company, $setting, $instance] = $this->context($instanceName);
        if (! $company || ! $setting || blank($setting->tattoo_artist_notify_phone) || $this->matchesArtist($setting, $phone)) {
            return null;
        }
        $found = $this->detector->detect($text);
        if ($found === null) {
            return null;
        }
        $phone = (string) PhoneNormalizer::normalize($phone);
        if (filled($messageId) && TattooManualQuoteDetection::query()->where('company_id', $company->id)
            ->where('provider_message_id', $messageId)->exists()) {
            return null;
        }
        $deposit = $found['deposit'] ?? ((float) $setting->tattoo_fixed_deposit_amount > 0 ? (float) $setting->tattoo_fixed_deposit_amount : null);
        $duplicate = TattooManualQuoteDetection::query()->where('company_id', $company->id)
            ->where('phone_normalized', $phone)->whereIn('status', ['pending', 'saved'])
            ->where('total', $found['total'])->where('created_at', '>=', now()->subDays(30))->exists();
        if ($duplicate) {
            return null;
        }
        $conversation = TattooAiConversation::query()->where('company_id', $company->id)
            ->where('company_whatsapp_instance_id', $instance->id)->where('phone_normalized', $phone)->first();

        $detection = new TattooManualQuoteDetection([
            'tattoo_ai_conversation_id' => $conversation?->id,
            'phone_normalized' => $phone,
            'customer_name' => $this->customerName($company, $conversation, $phone),
            'provider_message_id' => filled($messageId) ? $messageId : null,
            'message_text' => Str::limit($text, 4000, ''),
            'total' => $found['total'],
            'deposit' => $deposit,
            'deposit_written' => $found['deposit'] !== null,
            'candidates' => $found['totals'],
            'status' => 'pending',
        ]);
        $detection->company_id = $company->id;
        $detection->save();

        $this->notifyArtist($instance, $setting, $this->prompt($detection, $phone));

        return $detection;
    }

    /**
     * Mensagem recebida do número pessoal do tatuador. Retorna true quando o
     * número é o do tatuador: a mensagem nunca segue para o atendimento.
     */
    public function handleArtistMessage(string $instanceName, string $phone, string $text): bool
    {
        [$company, $setting, $instance] = $this->context($instanceName);
        if (! $company || ! $setting || ! $this->matchesArtist($setting, $phone)) {
            return false;
        }
        $answer = Str::of(Str::ascii($text))->lower()->squish()->rtrim('.!')->value();
        if (! preg_match('/^(1|2|sim|s|nao|n|salvar|salva|ignorar|ignora|confirmar|confirma|recusar|recusa)(?:\s*#?\s*([co])?\s*(\d+))?$/', $answer, $m)) {
            return true;
        }
        $yes = in_array($m[1], ['1', 'sim', 's', 'salvar', 'salva', 'confirmar', 'confirma'], true);
        $kind = $m[2] ?? '';
        $ref = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null;
        $receipts = TattooPaymentReceipt::query()->where('company_id', $company->id)
            ->where('payment_status', 'receipt_received')->whereNotNull('artist_notified_at');
        $detections = TattooManualQuoteDetection::query()->where('company_id', $company->id)->where('status', 'pending');
        $receipt = null;
        $detection = null;
        if ($ref !== null) {
            if ($kind !== 'o') {
                $receipt = TattooPaymentReceipt::query()->where('company_id', $company->id)->find($ref);
            }
            if ($receipt === null && $kind !== 'c') {
                $detection = (clone $detections)->find($ref);
            }
            if ($receipt === null && $detection === null) {
                $this->notifyArtist($instance, $setting, 'Não achei nada pendente com o código #'.strtoupper($kind).$ref.'.');

                return true;
            }
        } else {
            $receipt = (clone $receipts)->latest('artist_notified_at')->latest('id')->first();
            $detection = (clone $detections)->latest('id')->first();
            if ($receipt && $detection) {
                $receipt->artist_notified_at->gte($detection->created_at) ? $detection = null : $receipt = null;
            }
        }
        if ($receipt !== null) {
            $this->notifyArtist($instance, $setting, app(TattooReceiptConfirmationService::class)->answer($receipt, $yes));

            return true;
        }
        if ($detection === null) {
            return true;
        }
        $choice = $yes ? 'save' : 'ignore';
        $who = $detection->customer_name ?: $this->formatPhone($detection->phone_normalized);
        if ($choice === 'ignore') {
            $detection->update(['status' => 'ignored', 'resolved_at' => now()]);
            $this->notifyArtist($instance, $setting, 'Beleza, ignorei o orçamento do '.$who.'.');

            return true;
        }
        try {
            $quote = $this->save($company, $instance, $detection);
        } catch (Throwable $exception) {
            Log::warning('Tattoo manual quote: save failed.', ['company_id' => $company->id,
                'detection_id' => $detection->id, 'error' => $exception->getMessage()]);
            $this->notifyArtist($instance, $setting, 'Não consegui salvar o orçamento do '.$who.'. Lança pelo painel, por favor.');

            return true;
        }
        $this->notifyArtist($instance, $setting, 'Salvei o orçamento de '.$this->money((float) $quote->amount_min).' pro '.$who
            .' ✅ A IA segue com o aceite e o sinal quando o cliente responder.');

        return true;
    }

    public function save(Company $company, CompanyWhatsAppInstance $instance, TattooManualQuoteDetection $detection): TattooQuote
    {
        return DB::transaction(function () use ($company, $instance, $detection): TattooQuote {
            $detection = TattooManualQuoteDetection::query()->whereKey($detection->id)->lockForUpdate()->firstOrFail();
            $conversation = $detection->tattoo_ai_conversation_id ? TattooAiConversation::query()->find($detection->tattoo_ai_conversation_id) : null;
            $conversation ??= TattooAiConversation::query()->where('company_id', $company->id)
                ->where('company_whatsapp_instance_id', $instance->id)->where('phone_normalized', $detection->phone_normalized)->first();
            $client = $this->findClient($company, $detection->phone_normalized, $conversation)
                ?? app(ClientService::class)->create($company, ['name' => $detection->customer_name ?: 'Cliente WhatsApp',
                    'phone' => $detection->phone_normalized]);

            $request = TattooRequest::query()->where('company_id', $company->id)->where('client_id', $client->id)
                ->whereNotIn('status', ['cancelled', 'declined', 'booked'])->whereNull('appointment_id')->latest('id')->first();
            if ($request === null) {
                $request = new TattooRequest(['client_id' => $client->id, 'source' => 'whatsapp_manual',
                    'status' => 'quote_sent', 'description' => 'Orçamento combinado pelo WhatsApp (detalhes na conversa)',
                    'body_placement' => 'A definir']);
                $request->company_id = $company->id;
                $request->save();
            }

            $quote = new TattooQuote([
                'tattoo_request_id' => $request->id,
                'version' => (int) $request->quotes()->max('version') + 1,
                'price_type' => 'fixed',
                'amount_min' => $detection->total,
                'sessions' => 1,
                'deposit_amount' => $detection->deposit,
                'message_snapshot' => $detection->message_text,
                'sent_at' => $detection->created_at ?? now(),
            ]);
            $quote->company_id = $company->id;
            $quote->save();
            $request->update(['status' => 'quote_sent']);

            if ($conversation) {
                $conversation->update(['client_id' => $client->id, 'tattoo_request_id' => $request->id,
                    'human_takeover' => false, 'status' => 'waiting_professional_quote']);
                app(WhatsAppHumanTakeover::class)->resume($instance->instance_name, $detection->phone_normalized);
            }
            $detection->update(['status' => 'saved', 'resolved_at' => now(), 'tattoo_quote_id' => $quote->id]);

            return $quote;
        });
    }

    /** @return array{0: ?Company, 1: ?CompanySchedulingSetting, 2: ?CompanyWhatsAppInstance} */
    protected function context(string $instanceName): array
    {
        $instance = CompanyWhatsAppInstance::query()->where('instance_name', $instanceName)->first();
        $company = $instance ? Company::query()->find($instance->company_id) : null;
        if (! $company || ! $company->isTattooStudio()) {
            return [null, null, null];
        }
        $setting = CompanySchedulingSetting::query()->where('company_id', $company->id)->first();

        return [$company, $setting && filled($setting->tattoo_artist_notify_phone) ? $setting : null, $instance];
    }

    protected function matchesArtist(CompanySchedulingSetting $setting, string $phone): bool
    {
        $artist = PhoneNormalizer::normalize($setting->tattoo_artist_notify_phone);

        return $artist !== null && in_array($artist, PhoneNormalizer::candidates($phone), true);
    }

    protected function findClient(Company $company, string $phone, ?TattooAiConversation $conversation): ?Client
    {
        if ($conversation?->client_id) {
            $client = Client::query()->where('company_id', $company->id)->find($conversation->client_id);
            if ($client) {
                return $client;
            }
        }

        return Client::query()->where('company_id', $company->id)
            ->whereIn('phone_normalized', PhoneNormalizer::candidates($phone))->oldest('id')->first();
    }

    protected function customerName(Company $company, ?TattooAiConversation $conversation, string $phone): ?string
    {
        $data = $conversation?->collected_data ?: [];
        $name = $this->findClient($company, $phone, $conversation)?->name ?? ($data['name'] ?? null) ?? ($data['_name_candidate'] ?? null);

        return is_string($name) && trim($name) !== '' ? Str::limit(trim($name), 120, '') : null;
    }

    protected function prompt(TattooManualQuoteDetection $detection, string $phone): string
    {
        $who = $detection->customer_name ?: $this->formatPhone($phone);
        $deposit = $detection->deposit !== null && (float) $detection->deposit > 0
            ? 'sinal '.$this->money((float) $detection->deposit).($detection->deposit_written ? '' : ' (sinal fixo)')
            : 'sinal não definido';
        $values = array_map(fn ($value) => $this->money((float) $value), $detection->candidates ?: []);
        $ambiguous = count($values) > 1
            ? ' Vi mais de um valor ('.implode(', ', $values).'), considerei o primeiro; se não for, responde 2 e lança pelo painel.'
            : '';

        return 'Orçamento detectado pro '.$who.': total '.$this->money((float) $detection->total).', '.$deposit.'.'
            .$ambiguous.' Responde 1 pra salvar ou 2 pra ignorar (#O'.$detection->id.').';
    }

    protected function notifyArtist(?CompanyWhatsAppInstance $instance, CompanySchedulingSetting $setting, string $message): void
    {
        if (! $instance) {
            return;
        }
        try {
            app(EvolutionApiClient::class)->sendText($instance->instance_name, (string) PhoneNormalizer::normalize($setting->tattoo_artist_notify_phone), $message);
        } catch (Throwable $exception) {
            Log::warning('Tattoo manual quote: artist notification failed.', ['company_id' => $setting->company_id,
                'error' => $exception->getMessage()]);
        }
    }

    protected function money(float $value): string
    {
        return 'R$ '.number_format($value, 2, ',', '.');
    }

    protected function formatPhone(string $phone): string
    {
        return preg_replace('/^55(\d{2})(\d{4,5})(\d{4})$/', '($1) $2-$3', $phone) ?: $phone;
    }
}
