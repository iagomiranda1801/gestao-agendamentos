<?php

namespace App\Services\Asaas;

use App\Exceptions\AsaasApiException;
use Illuminate\Support\Facades\Http;

class BaseFiscalClient
{
    public function send(string $key, string $method, string $path, array $payload = [], ?string $reference = null): array
    {
        $request = Http::baseUrl('https://api-sandbox.baseerp.com.br/api/v2')
            ->withHeaders(['access_token' => $key, 'X-Consumer' => 'AgendaAqui'])
            ->acceptJson()
            ->asJson()
            ->timeout(20);

        if ($reference !== null) {
            $request->withHeader('Idempotency-Key', $reference);
        }

        $response = $request->{$method}($path, $payload);

        if ($response->failed()) {
            throw new AsaasApiException('O Base ERP recusou a solicitação (HTTP '.$response->status().'). Confira o cadastro fiscal e os dados do pedido no Base.', $response->status());
        }

        return $response->json() ?? [];
    }
}
