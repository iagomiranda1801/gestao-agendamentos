<?php

namespace App\Services\Orders;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PublicOrderRateLimiter
{
    public function assertCreateAttemptAllowed(int $companyId, string $ip, ?string $phoneNormalized): void
    {
        $ipKey = "public-order:create:ip:{$companyId}:".hash('sha256', $ip);

        if (RateLimiter::tooManyAttempts($ipKey, 8)) {
            throw ValidationException::withMessages([
                'rate_limit' => 'Muitas tentativas de pedido. Aguarde alguns minutos e tente novamente.',
            ]);
        }

        RateLimiter::hit($ipKey, 600);

        if (filled($phoneNormalized)) {
            $phoneKey = "public-order:create:phone:{$companyId}:".hash('sha256', $phoneNormalized);

            if (RateLimiter::tooManyAttempts($phoneKey, 4)) {
                throw ValidationException::withMessages([
                    'rate_limit' => 'Muitas tentativas de pedido. Aguarde alguns minutos e tente novamente.',
                ]);
            }

            RateLimiter::hit($phoneKey, 1800);
        }
    }
}
