<?php

namespace App\Jobs\Concerns;

use App\Enums\WhatsAppOutboundKind;
use App\Models\Company;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundGate;
use App\Services\WhatsApp\Outbound\WhatsAppOutboundReservation;
use Illuminate\Support\Facades\Log;
use Throwable;

trait DefersViaWhatsAppOutboundGate
{
    public ?int $whatsappOutboundNotBefore = null;

    public int $whatsappOutboundRetrySeconds = 0;

    public int $tries = 200;

    public int $maxExceptions = 5;

    protected function deferUntilOutboundSlot(Company $company, WhatsAppOutboundKind $kind): bool
    {
        $this->whatsappOutboundRetrySeconds = 0;
        $gate = app(WhatsAppOutboundGate::class);
        $inspection = $gate->inspect($company, $kind);

        if ($this->shouldDeferReservation($inspection)) {
            return $this->deferReservation($company, $kind, $inspection);
        }

        $reservation = $gate->reserve($company, $kind);

        if ($this->shouldDeferReservation($reservation)) {
            return $this->deferReservation($company, $kind, $reservation);
        }

        return true;
    }

    protected function shouldDeferReservation(WhatsAppOutboundReservation $reservation): bool
    {
        if (! $reservation->allowed) {
            return true;
        }

        $availableAt = $reservation->availableAt?->getTimestamp() ?? now()->getTimestamp();

        return $availableAt > now()->getTimestamp();
    }

    protected function deferReservation(
        Company $company,
        WhatsAppOutboundKind $kind,
        WhatsAppOutboundReservation $reservation,
    ): bool {
        $retryAt = $reservation->retryAt ?? $reservation->availableAt;
        $wait = $retryAt !== null && $retryAt->getTimestamp() > now()->getTimestamp()
            ? max(1, $retryAt->getTimestamp() - now()->getTimestamp())
            : 0;
        $reason = $reservation->reason ?? 'interval';

        $this->whatsappOutboundRetrySeconds = $wait;
        $this->logOutboundDeferral($company, $kind, $reason, $wait);

        if ($wait > 0) {
            $this->release($wait);
        }

        return false;
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
