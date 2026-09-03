<?php

namespace App\Scheduling;

use Illuminate\Validation\ValidationException;

readonly class AvailabilityResult
{
    public function __construct(
        public bool $available,
        public ?string $reason = null,
    ) {}

    public static function ok(): self
    {
        return new self(true);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }

    public function assertAvailable(): void
    {
        if (! $this->available) {
            $reason = $this->reason ?? 'Horário indisponível.';

            throw ValidationException::withMessages([
                'appointment_time' => $reason,
                'start_at' => $reason,
            ]);
        }
    }
}
