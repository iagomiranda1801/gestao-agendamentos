<?php

namespace App\Services\PublicBooking;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PublicBookingRateLimiter
{
    public function assertAvailabilityCheckAllowed(int $companyId, string $ip): void
    {
        $key = "public-booking:availability:{$companyId}:".hash('sha256', $ip);

        if (RateLimiter::tooManyAttempts($key, 60)) {
            throw ValidationException::withMessages([
                'rate_limit' => 'Muitas consultas em pouco tempo. Aguarde um momento e tente novamente.',
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    public function assertCreateAttemptAllowed(int $companyId, string $ip, ?string $phoneNormalized): void
    {
        $ipKey = "public-booking:create:ip:{$companyId}:".hash('sha256', $ip);

        if (RateLimiter::tooManyAttempts($ipKey, 5)) {
            throw ValidationException::withMessages([
                'rate_limit' => 'Muitas tentativas de agendamento. Aguarde alguns minutos e tente novamente.',
            ]);
        }

        RateLimiter::hit($ipKey, 600);

        if (filled($phoneNormalized)) {
            $phoneKey = "public-booking:create:phone:{$companyId}:".hash('sha256', $phoneNormalized);

            if (RateLimiter::tooManyAttempts($phoneKey, 3)) {
                throw ValidationException::withMessages([
                    'rate_limit' => 'Muitas tentativas de agendamento. Aguarde alguns minutos e tente novamente.',
                ]);
            }

            RateLimiter::hit($phoneKey, 1800);
        }
    }

    public function assertManageViewAllowed(string $ip): void
    {
        $key = 'public-booking:manage:view:'.hash('sha256', $ip);

        if (RateLimiter::tooManyAttempts($key, 30)) {
            throw ValidationException::withMessages([
                'rate_limit' => 'Muitas consultas em pouco tempo. Aguarde um momento e tente novamente.',
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    public function assertManageActionAllowed(string $tokenHash, string $ip): void
    {
        $key = 'public-booking:manage:action:'.hash('sha256', $tokenHash.':'.$ip);

        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages([
                'rate_limit' => 'Muitas ações em pouco tempo. Aguarde um momento e tente novamente.',
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    public function assertClientLookupAllowed(int $companyId, string $ip): void
    {
        $key = "public-booking:client-lookup:{$companyId}:".hash('sha256', $ip);

        if (RateLimiter::tooManyAttempts($key, 20)) {
            throw ValidationException::withMessages([
                'clientPhone' => 'Muitas consultas. Aguarde um momento e tente novamente.',
            ]);
        }

        RateLimiter::hit($key, 60);
    }

    /** @deprecated Use assertClientLookupAllowed() */
    public function assertCpfLookupAllowed(int $companyId, string $ip): void
    {
        $this->assertClientLookupAllowed($companyId, $ip);
    }
}
