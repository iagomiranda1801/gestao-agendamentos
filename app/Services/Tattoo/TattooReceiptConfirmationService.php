<?php

namespace App\Services\Tattoo;

use App\Models\TattooPaymentReceipt;
use App\Services\WhatsApp\EvolutionApiClient;
use App\Services\WhatsApp\WhatsAppHumanTakeover;
use App\Support\PhoneNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Comprovante do sinal encaminhado pro WhatsApp pessoal do tatuador: ele
 * responde 1 (confirma, mesmo caminho do botão "Confirmar sinal") ou 2
 * (recusa e assume a conversa). A leitura automática nunca confirma sozinha.
 */
class TattooReceiptConfirmationService
{
    public function __construct(protected EvolutionApiClient $evolution) {}

    public static function code(TattooPaymentReceipt $receipt): string
    {
        return '#C'.$receipt->id;
    }

    /** Envia o comprovante + resumo ao tatuador. Sem número configurado, fica só no painel. */
    public function forward(TattooPaymentReceipt $receipt): bool
    {
        $receipt->loadMissing(['conversation.instance', 'conversation.company.schedulingSetting', 'quote', 'request.client']);
        $conversation = $receipt->conversation;
        $setting = $conversation?->company?->schedulingSetting;
        $artist = PhoneNormalizer::normalize((string) $setting?->tattoo_artist_notify_phone);
        if (! $artist || ! $conversation->instance || $receipt->artist_notified_at !== null
            || $receipt->payment_status !== 'receipt_received') {
            return false;
        }
        $caption = $this->summary($receipt);
        $instance = $conversation->instance->instance_name;
        $sentMedia = false;
        try {
            $binary = Storage::disk($receipt->disk)->get($receipt->path);
            if (is_string($binary) && $binary !== '') {
                if ($receipt->mime_type === 'application/pdf') {
                    $this->evolution->sendDocument($instance, (string) $artist, $binary, 'application/pdf', 'comprovante-'.$receipt->id.'.pdf', $caption);
                } else {
                    $this->evolution->sendImage($instance, (string) $artist, $binary, $receipt->mime_type, 'comprovante-'.$receipt->id, $caption);
                }
                $sentMedia = true;
            }
        } catch (Throwable $exception) {
            Log::warning('Tattoo receipt: forward media to artist failed.', ['receipt_id' => $receipt->id, 'error_type' => $exception::class]);
        }
        if (! $sentMedia) {
            try {
                $this->evolution->sendText($instance, (string) $artist, $caption."\n(Não consegui anexar o arquivo; ele está no painel.)");
            } catch (Throwable $exception) {
                Log::warning('Tattoo receipt: forward text to artist failed.', ['receipt_id' => $receipt->id, 'error_type' => $exception::class]);

                return false;
            }
        }
        $receipt->forceFill(['artist_notified_at' => now()])->save();

        return true;
    }

    public function summary(TattooPaymentReceipt $receipt): string
    {
        $conversation = $receipt->conversation;
        $name = trim((string) ($receipt->request?->client?->name ?? ($conversation?->collected_data['name'] ?? ''))) ?: 'Cliente';
        $phone = (string) $conversation?->phone_normalized;
        $slot = ($conversation?->collected_data ?: [])['_schedule'] ?? null;
        $when = 'ainda não escolheu';
        if (($slot['stage'] ?? null) === 'chosen' && ! empty($slot['slot'])) {
            $when = CarbonImmutable::parse((string) $slot['slot'])->format('d/m').' às '.substr((string) $slot['slot'], 11, 5);
        }
        $warnings = $receipt->analysis['warnings'] ?? [];
        $amount = $receipt->analysis['amount'] ?? null;
        $checks = match ($receipt->receipt_analysis_status) {
            'compatible' => 'leitura automática OK, sem alertas',
            'pending' => 'leitura automática falhou',
            'unreadable' => 'leitura automática não reconheceu um comprovante PIX',
            default => 'leitura automática com alertas',
        };

        return 'Comprovante de sinal recebido '.self::code($receipt)."\n"
            .'Cliente: '.$name.' ('.$this->formatPhone($phone).")\n"
            .'Horário escolhido: '.$when."\n"
            .'Sinal esperado: '.$this->money((float) $receipt->quote?->deposit_amount)
            .($amount !== null ? ' · lido no comprovante: '.$this->money((float) $amount) : '')."\n"
            .'Conferência: '.$checks.($warnings !== [] ? "\nAlertas: ".implode('; ', $warnings) : '')."\n\n"
            .'Responde 1 pra confirmar o sinal ou 2 pra recusar ('.self::code($receipt).').';
    }

    /** Resposta do tatuador. Retorna o texto de retorno pra ele. */
    public function answer(TattooPaymentReceipt $receipt, bool $confirm): string
    {
        $who = trim((string) $receipt->request?->client?->name) ?: $this->formatPhone((string) $receipt->conversation?->phone_normalized);
        $service = app(TattooReceiptService::class);
        if (! $service->review($receipt, null, $confirm, 'whatsapp')) {
            return 'Esse comprovante ('.self::code($receipt).') já tinha sido resolvido, não mudei nada.';
        }
        $receipt->refresh();
        if ($confirm) {
            $request = $receipt->request?->fresh();

            return $request?->appointment_id
                ? 'Sinal do '.$who.' confirmado ✅ e horário agendado.'
                : 'Sinal do '.$who.' confirmado ✅. O horário ainda não estava definido (ou foi ocupado), mandei os dias livres pra ele escolher sem pagar de novo.';
        }
        $conversation = $receipt->conversation;
        if ($conversation) {
            $conversation->update(['human_takeover' => true, 'status' => 'human_takeover']);
            if ($conversation->instance) {
                app(WhatsAppHumanTakeover::class)->pause($conversation->instance->instance_name, $conversation->phone_normalized);
            }
            $artist = trim((string) $conversation->company?->schedulingSetting?->tattoo_artist_name) ?: 'o tatuador';
            app(TattooBookingFlow::class)->sendToCustomer($conversation,
                'Não consegui confirmar teu comprovante por aqui 😕 '.$artist.' vai falar contigo pra resolver.', 'receipt-rejected:'.$receipt->id);
        }

        return 'Comprovante do '.$who.' recusado. Avisei o cliente que você vai falar com ele; a IA ficou pausada nessa conversa.';
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
