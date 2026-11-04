<?php

namespace App\Services\Asaas;

use App\Enums\AsaasEnvironment;
use App\Exceptions\AsaasApiException;
use App\Models\Client;
use App\Support\DecimalMoney;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class AsaasClient
{
    public function baseUrl(AsaasEnvironment $environment): string
    {
        return $environment === AsaasEnvironment::Production
            ? 'https://api.asaas.com/v3'
            : 'https://api-sandbox.asaas.com/v3';
    }

    public function balance(AsaasEnvironment $environment, string $apiKey): string
    {
        $payload = $this->send($environment, $apiKey, 'get', '/finance/balance');

        return number_format((float) ($payload['balance'] ?? 0), 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    public function createCustomer(AsaasEnvironment $environment, string $apiKey, Client $client): array
    {
        return $this->send($environment, $apiKey, 'post', '/customers', $this->customerPayload($client));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createPayment(AsaasEnvironment $environment, string $apiKey, array $payload): array
    {
        return $this->send($environment, $apiKey, 'post', '/payments', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function pixQrCode(AsaasEnvironment $environment, string $apiKey, string $paymentId): array
    {
        return $this->send($environment, $apiKey, 'get', '/payments/'.$paymentId.'/pixQrCode');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function send(AsaasEnvironment $environment, string $apiKey, string $method, string $path, array $payload = []): array
    {
        $request = $this->request($environment, $apiKey);
        $response = $payload === []
            ? $request->{$method}($path)
            : $request->{$method}($path, $payload);

        if ($response->failed()) {
            throw AsaasApiException::fromResponse($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    protected function request(AsaasEnvironment $environment, string $apiKey): PendingRequest
    {
        return Http::baseUrl($this->baseUrl($environment))
            ->withHeaders([
                'access_token' => $apiKey,
                'User-Agent' => 'Agendaqui',
            ])
            ->acceptJson()
            ->asJson()
            ->timeout(20);
    }

    /**
     * @return array<string, mixed>
     */
    protected function customerPayload(Client $client): array
    {
        $payload = [
            'name' => mb_substr(trim($client->name), 0, 100),
            'externalReference' => 'client:'.$client->getKey(),
            'notificationDisabled' => true,
        ];

        $document = preg_replace('/\D/', '', (string) $client->document) ?? '';
        if (in_array(strlen($document), [11, 14], true)) {
            $payload['cpfCnpj'] = $document;
        }

        if (filled($client->email) && filter_var($client->email, FILTER_VALIDATE_EMAIL)) {
            $payload['email'] = $client->email;
        }

        $phone = preg_replace('/\D/', '', (string) $client->phone) ?? '';
        if (str_starts_with($phone, '55') && strlen($phone) > 11) {
            $phone = substr($phone, 2);
        }
        if (strlen($phone) >= 10 && strlen($phone) <= 11) {
            $payload['mobilePhone'] = $phone;
        }

        return $payload;
    }

    public function money(mixed $amount): string
    {
        if (is_string($amount) && is_numeric($amount)) {
            return DecimalMoney::round($amount);
        }

        return number_format((float) $amount, 2, '.', '');
    }
}
