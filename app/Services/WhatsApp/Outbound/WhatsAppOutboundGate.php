<?php

namespace App\Services\WhatsApp\Outbound;

use App\Enums\WhatsAppOutboundKind;
use App\Models\Company;
use App\Support\CompanyDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class WhatsAppOutboundGate
{
    public function reserve(Company $company, WhatsAppOutboundKind $kind): WhatsAppOutboundReservation
    {
        $lock = Cache::lock($this->key($company, 'lock'), 10);

        try {
            $lock->block(5);

            return $this->reserveLocked($company, $kind);
        } finally {
            optional($lock)->release();
        }
    }

    public function allowsMarketing(Company $company): bool
    {
        return $this->inspect($company, WhatsAppOutboundKind::Marketing)->allowed;
    }

    public function inspect(Company $company, WhatsAppOutboundKind $kind): WhatsAppOutboundReservation
    {
        $lock = Cache::lock($this->key($company, 'lock'), 10);

        try {
            $lock->block(5);

            return $this->decide($company, $kind, consume: false);
        } finally {
            optional($lock)->release();
        }
    }

    public function recordSuccess(Company $company): void
    {
        Cache::put($this->key($company, 'failures'), 0, now()->addDay());
        Cache::forget($this->key($company, 'breaker'));
        $this->incrementDailyCount($company);
    }

    public function recordFailure(Company $company): void
    {
        $key = $this->key($company, 'failures');
        Cache::add($key, 0, now()->addDay());
        $failures = (int) Cache::increment($key);

        if ($failures >= $this->circuitFailures()) {
            Cache::put(
                $this->key($company, 'breaker'),
                now()->addMinutes($this->circuitPauseMinutes())->getTimestamp(),
                now()->addMinutes($this->circuitPauseMinutes() + 5),
            );
            Cache::put($this->key($company, 'failures'), 0, now()->addDay());
        }
    }

    protected function reserveLocked(Company $company, WhatsAppOutboundKind $kind): WhatsAppOutboundReservation
    {
        return $this->decide($company, $kind, consume: true);
    }

    protected function decide(Company $company, WhatsAppOutboundKind $kind, bool $consume): WhatsAppOutboundReservation
    {
        $local = CompanyDateTime::nowLocal($company);

        if ($kind->skipsSunday() && $local->isSunday()) {
            $monday = $local->addDay()->startOfDay()->setTime(8, 0);

            return WhatsAppOutboundReservation::retryLater(
                Carbon::instance($monday->utc()),
                'sunday',
            );
        }

        $breakerUntil = $this->breakerUntil($company);

        if ($breakerUntil !== null && $breakerUntil->isFuture() && ! $kind->bypassesCircuitBreaker()) {
            return WhatsAppOutboundReservation::retryLater($breakerUntil, 'circuit_breaker');
        }

        if (! $kind->bypassesDailyLimit() && $this->dailyCount($company) >= $this->dailyLimit()) {
            return WhatsAppOutboundReservation::retryLater($this->nextLocalMorning($company), 'daily_cap');
        }

        $availableAt = $this->nextSlot($company);

        if ($consume) {
            $interval = $this->nextIntervalSeconds();
            Cache::put(
                $this->key($company, 'next'),
                $availableAt->copy()->addSeconds($interval)->getTimestamp(),
                now()->addDay(),
            );
        }

        return WhatsAppOutboundReservation::ready($availableAt);
    }

    protected function nextSlot(Company $company): Carbon
    {
        $stored = Cache::get($this->key($company, 'next'));
        $next = is_numeric($stored) ? Carbon::createFromTimestamp((int) $stored) : now();

        return $next->greaterThan(now()) ? $next : now();
    }

    protected function nextIntervalSeconds(): int
    {
        $min = max(1, $this->intConfig('min_interval_seconds', 30));
        $max = max($min, $this->intConfig('max_interval_seconds', 45));
        $base = random_int($min, $max);
        $jitter = $this->intConfig('jitter_seconds', 10);

        if ($jitter <= 0) {
            return $base;
        }

        return max(1, $base + random_int(-$jitter, $jitter));
    }

    protected function dailyCount(Company $company): int
    {
        $date = CompanyDateTime::nowLocal($company)->toDateString();

        return (int) Cache::get($this->key($company, "count:{$date}"), 0);
    }

    protected function incrementDailyCount(Company $company): void
    {
        $date = CompanyDateTime::nowLocal($company)->toDateString();
        $key = $this->key($company, "count:{$date}");
        $end = CompanyDateTime::parseLocal($company, $date, '00:00:00')->addDay()->utc();

        Cache::add($key, 0, $end);
        Cache::increment($key);
    }

    protected function breakerUntil(Company $company): ?Carbon
    {
        $until = Cache::get($this->key($company, 'breaker'));

        if (! is_numeric($until)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $until);
    }

    protected function nextLocalMorning(Company $company): Carbon
    {
        $local = CompanyDateTime::nowLocal($company)->addDay()->startOfDay()->setTime(8, 0);

        return Carbon::instance($local->utc());
    }

    protected function dailyLimit(): int
    {
        return max(1, $this->intConfig('daily_limit', 80));
    }

    protected function circuitFailures(): int
    {
        return max(1, $this->intConfig('circuit_failures', 5));
    }

    protected function circuitPauseMinutes(): int
    {
        return max(1, $this->intConfig('circuit_pause_minutes', 120));
    }

    protected function intConfig(string $key, int $default): int
    {
        return (int) config("services.evolution.outbound.{$key}", $default);
    }

    protected function key(Company $company, string $suffix): string
    {
        return "wa:outbound:{$company->getKey()}:{$suffix}";
    }
}
