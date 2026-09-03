<?php

namespace App\Jobs\Concerns;

use App\Enums\WhatsAppOutboundKind;
use App\Models\Company;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundGate;
use Illuminate\Support\Facades\Log;
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
                $wait = $reservation->retryAt !== null && $reservation->retryAt->isFuture()
                    ? max(1, now()->diffInSeconds($reservation->retryAt))
                    : 0;

                $this->logOutboundDeferral($company, $kind, $reservation->reason ?? 'blocked', $wait);

                if ($wait > 0) {
                    $this->release($wait);
                }

                return false;
            }

            $this->whatsappOutboundNotBefore = $reservation->availableAt?->getTimestamp() ?? now()->getTimestamp();
        }

        $wait = $this->whatsappOutboundNotBefore - now()->getTimestamp();

        if ($wait > 0) {
            $this->logOutboundDeferral($company, $kind, 'interval', $wait);
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

    protected function logOutboundDeferral(Company $company, WhatsAppOutboundKind $kind, string $reason, int $retryInSeconds): void
    {
        Log::info('WhatsApp outbound deferred.', [
            'job' => static::class,
            'kind' => $kind->value,
            'company_id' => $company->getKey(),
            'reason' => $reason,
            'retry_in_seconds' => $retryInSeconds,
        ]);
    }
}
