<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class EvolutionApiClient
{
    public function resolveInstance(?string $companyInstance = null): string
    {
        return trim((string) ($companyInstance ?: config('services.evolution.instance')));
    }

    public function sendText(string $instance, string $phoneDigits, string $message): void
    {
        $baseUrl = rtrim((string) config('services.evolution.url'), '/');
        $apiKey = (string) config('services.evolution.key');

        if ($baseUrl === '' || $apiKey === '') {
            throw new RuntimeException('Evolution API não configurada (EVOLUTION_API_URL / EVOLUTION_API_KEY).');
        }

        $instance = $this->resolveInstance($instance);

        if ($instance === '') {
            throw new RuntimeException('Instância Evolution não configurada.');
        }

        $number = $this->toWhatsAppNumber($phoneDigits);

        $response = Http::withHeaders([
            'apikey' => $apiKey,
            'Content-Type' => 'application/json',
        ])
            ->acceptJson()
            ->timeout(20)
            ->post("{$baseUrl}/message/sendText/{$instance}", [
                'number' => $number,
                'text' => $message,
            ]);

        if ($response->failed()) {
            throw new RequestException($response);
        }
    }

    protected function toWhatsAppNumber(string $phoneDigits): string
    {
        $digits = preg_replace('/\D+/', '', $phoneDigits) ?? '';

        if ($digits === '') {
            throw new RuntimeException('Telefone inválido para WhatsApp.');
        }

        if (! str_starts_with($digits, '55') && strlen($digits) >= 10 && strlen($digits) <= 11) {
            $digits = '55'.$digits;
        }

        return $digits;
    }
}
