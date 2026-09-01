<?php

namespace App\Jobs\Concerns;

use App\Enums\WhatsAppOutboundKind;
use App\Models\Company;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundGate;
use Throwable;

trait DefersViaWhatsAppOutboundGate
{
    public ?int $whatsappOutboundNotBefore = null;

    public int $tries = 200;

    public int $maxExceptions = 5;

    protected function deferUntilOutboundSlot(Company $company, WhatsAppOutboundKind $kind): bool
    {
        $gate = app(WhatsAppOutboundGate::class);

        if ($this->whatsappOutboundNotBefore === null) {
            $reservation = $gate->reserve($company, $kind);

            if (! $reservation->allowed) {
                if ($reservation->retryAt !== null && $reservation->retryAt->isFuture()) {
                    $this->release(max(1, now()->diffInSeconds($reservation->retryAt)));
                }

                return false;
            }

            $this->whatsappOutboundNotBefore = $reservation->availableAt?->getTimestamp() ?? now()->getTimestamp();
        }

        $wait = $this->whatsappOutboundNotBefore - now()->getTimestamp();

        if ($wait > 0) {
            $this->release($wait);

            return false;
        }

        return true;
    }

    protected function rememberOutboundSuccess(Company $company): void
    {
        app(WhatsAppOutboundGate::class)->recordSuccess($company);
    }

    protected function rememberOutboundFailure(Company $company): void
    {
        app(WhatsAppOutboundGate::class)->recordFailure($company);
    }

    protected function rememberOutboundFailureAndMaybeRethrow(Company $company, Throwable $exception): void
    {
        $this->rememberOutboundFailure($company);

        if (config('queue.default') !== 'sync') {
            throw $exception;
        }
    }
}
