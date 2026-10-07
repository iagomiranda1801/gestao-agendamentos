<?php

namespace App\Services\WhatsApp;

use App\Models\CompanyWhatsAppInstance;
use App\Models\WhatsAppBotConversation;
use App\Support\PhoneNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Quando a empresa responde manualmente pelo WhatsApp (mensagem fromMe que
 * nao foi enviada pelo sistema), o bot fica em silencio naquela conversa.
 */
class WhatsAppHumanTakeover
{
    public function rememberBotSend(string $instance, string $phone, ?string $text, ?string $messageId = null): void
    {
        $ttl = now()->addMinutes(10);

        if (filled($messageId)) {
            Cache::put("wa:bot-sent-id:{$messageId}", true, $ttl);
        }

        $key = $this->phoneKey($phone);
        $hash = $this->textHash($text);

        if ($key !== null && $hash !== null) {
            Cache::put($this->echoKey($instance, $key, $hash), true, $ttl);
        }
    }

    public function isBotEcho(string $instance, string $phone, ?string $text, ?string $messageId = null): bool
    {
        if (filled($messageId) && Cache::has("wa:bot-sent-id:{$messageId}")) {
            return true;
        }

        $key = $this->phoneKey($phone);
        $hash = $this->textHash($text);

        return $key !== null && $hash !== null && Cache::has($this->echoKey($instance, $key, $hash));
    }

    public function pause(string $instance, string $phone): void
    {
        $key = $this->phoneKey($phone);
        $minutes = $this->minutes();

        if ($key === null || $minutes <= 0) {
            return;
        }

        Cache::put($this->pauseKey($instance, $key), now()->addMinutes($minutes)->timestamp, now()->addMinutes($minutes));

        $companyId = CompanyWhatsAppInstance::query()->where('instance_name', $instance)->value('company_id');

        if ($companyId !== null) {
            WhatsAppBotConversation::query()
                ->where('company_id', $companyId)
                ->whereIn('phone_normalized', PhoneNormalizer::candidates($phone))
                ->whereNull('finished_at')
                ->update(['finished_at' => now(), 'finished_reason' => 'human_takeover']);
        }
    }

    public function isPaused(string $instance, string $phone): bool
    {
        $key = $this->phoneKey($phone);

        return $key !== null && Cache::has($this->pauseKey($instance, $key));
    }

    public function resume(string $instance, string $phone): void
    {
        $key = $this->phoneKey($phone);

        if ($key !== null) {
            Cache::forget($this->pauseKey($instance, $key));
        }
    }

    /**
     * Guarda quando o cliente mandou a ultima mensagem, para reconhecer as
     * respostas automaticas do WhatsApp Business (saudacao e ausencia), que
     * saem do numero da empresa logo em seguida.
     */
    public function rememberInbound(string $instance, string $phone): void
    {
        $key = $this->phoneKey($phone);

        if ($key !== null) {
            Cache::put($this->inboundKey($instance, $key), now()->getTimestamp(), now()->addMinutes(10));
        }
    }

    public function isLikelyAutomaticReply(string $instance, string $phone): bool
    {
        $key = $this->phoneKey($phone);
        $grace = (int) config('services.evolution.auto_reply_grace_seconds', 20);

        if ($key === null || $grace <= 0) {
            return false;
        }

        $last = Cache::get($this->inboundKey($instance, $key));

        return is_numeric($last) && now()->getTimestamp() - (int) $last <= $grace;
    }

    protected function inboundKey(string $instance, string $phoneKey): string
    {
        return "wa:last-inbound:{$instance}:{$phoneKey}";
    }

    public function minutes(): int
    {
        return (int) config('services.evolution.human_takeover_minutes', 120);
    }

    /**
     * Chave canonica do telefone: sem 55 e sem o nono digito, para casar
     * o remoteJid da Evolution com o numero usado no envio.
     */
    protected function phoneKey(string $phone): ?string
    {
        $digits = PhoneNormalizer::normalize($phone);

        if ($digits === null) {
            return null;
        }

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11 && $digits[2] === '9') {
            $digits = substr($digits, 0, 2).substr($digits, 3);
        }

        return $digits;
    }

    protected function textHash(?string $text): ?string
    {
        $normalized = Str::lower(trim((string) preg_replace('/\s+/u', ' ', (string) $text)));

        return $normalized === '' ? null : sha1($normalized);
    }

    protected function echoKey(string $instance, string $phoneKey, string $hash): string
    {
        return "wa:bot-sent:{$instance}:{$phoneKey}:{$hash}";
    }

    protected function pauseKey(string $instance, string $phoneKey): string
    {
        return "wa:human-takeover:{$instance}:{$phoneKey}";
    }
}
