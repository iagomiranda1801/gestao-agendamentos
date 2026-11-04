<?php

namespace App\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;

class AsaasApiException extends RuntimeException
{
    public static function fromResponse(Response $response): self
    {
        $description = data_get($response->json(), 'errors.0.description');

        return new self(is_string($description) && $description !== ''
            ? $description
            : 'Não foi possível falar com o Asaas.');
    }
}
