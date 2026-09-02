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

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $instance, string $phoneDigits, string $message): array
    {
        $instance = $this->resolveInstance($instance);

        if ($instance === '') {
            throw new RuntimeException('Instância Evolution não configurada.');
        }

        $number = $this->toWhatsAppNumber($phoneDigits);

        $response = $this->http()
            ->acceptJson()
            ->timeout(20)
            ->post($this->url("/message/sendText/{$instance}"), [
                'number' => $number,
                'text' => $message,
            ]);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function sendImage(
        string $instance,
        string $phoneDigits,
        string $binary,
        string $mimeType,
        string $fileName,
        string $caption,
    ): array {
        $instance = $this->resolveInstance($instance);

        if ($instance === '') {
            throw new RuntimeException('Instância Evolution não configurada.');
        }

        $number = $this->toWhatsAppNumber($phoneDigits);
        $mimeType = strtolower(trim($mimeType));

        if (! in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new RuntimeException('Tipo de imagem não suportado para campanha WhatsApp.');
        }

        $response = $this->http()
            ->acceptJson()
            ->timeout(45)
            ->post($this->url("/message/sendMedia/{$instance}"), [
                'number' => $number,
                'mediatype' => 'image',
                'mimetype' => $mimeType,
                'caption' => $caption,
                'media' => base64_encode($binary),
                'fileName' => $fileName,
            ]);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchInstances(): array
    {
        $response = $this->http()
            ->acceptJson()
            ->timeout(20)
            ->get($this->url('/instance/fetchInstances'));

        if ($response->failed()) {
            throw new RequestException($response);
        }

        $payload = $response->json() ?? [];

        if (isset($payload['instances']) && is_array($payload['instances'])) {
            return array_values(array_filter($payload['instances'], 'is_array'));
        }

        return is_array($payload) ? array_values(array_filter($payload, 'is_array')) : [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findContacts(string $instance, int $take = 200, int $skip = 0): array
    {
        $instance = $this->resolveInstance($instance);

        if ($instance === '') {
            throw new RuntimeException('Instância Evolution não configurada.');
        }

        $response = $this->http()
            ->acceptJson()
            ->timeout(30)
            ->post($this->url("/chat/findContacts/{$instance}"), [
                'where' => new \stdClass(),
                'take' => $take,
                'skip' => $skip,
                'orderBy' => new \stdClass(),
            ]);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        $payload = $response->json() ?? [];

        if (isset($payload['contacts']) && is_array($payload['contacts'])) {
            return array_values(array_filter($payload['contacts'], 'is_array'));
        }

        return is_array($payload) ? array_values(array_filter($payload, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>
     */
    public function createInstance(string $instanceName, ?string $token = null, bool $qrcode = true): array
    {
        $payload = [
            'instanceName' => $instanceName,
            'qrcode' => $qrcode,
            'integration' => 'WHATSAPP-BAILEYS',
        ];

        if (filled($token)) {
            $payload['token'] = $token;
        }

        $response = $this->http()
            ->acceptJson()
            ->timeout(30)
            ->post($this->url('/instance/create'), $payload);

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function connectInstance(string $instanceName): array
    {
        $response = $this->http()
            ->acceptJson()
            ->timeout(30)
            ->get($this->url("/instance/connect/{$instanceName}"));

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteInstance(string $instanceName): array
    {
        $instanceName = $this->resolveInstance($instanceName);

        if ($instanceName === '') {
            throw new RuntimeException('Instância Evolution não configurada.');
        }

        $response = $this->http()
            ->acceptJson()
            ->timeout(30)
            ->delete($this->url("/instance/delete/{$instanceName}"));

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response->json() ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function connectionState(string $instanceName): array
    {
        $response = $this->http()
            ->acceptJson()
            ->timeout(20)
            ->get($this->url("/instance/connectionState/{$instanceName}"));

        if ($response->failed()) {
            throw new RequestException($response);
        }

        return $response->json() ?? [];
    }

    protected function url(string $path): string
    {
        $baseUrl = rtrim((string) config('services.evolution.url'), '/');

        if ($baseUrl === '') {
            throw new RuntimeException('Evolution API não configurada (EVOLUTION_API_URL).');
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    protected function http(): \Illuminate\Http\Client\PendingRequest
    {
        $apiKey = (string) config('services.evolution.key');

        if ($apiKey === '') {
            throw new RuntimeException('Evolution API não configurada (EVOLUTION_API_KEY).');
        }

        return Http::withHeaders([
            'apikey' => $apiKey,
            'Content-Type' => 'application/json',
        ]);
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
