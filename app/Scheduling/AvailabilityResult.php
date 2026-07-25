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
            throw ValidationException::withMessages([
                'start_at' => $this->reason ?? 'Horário indisponível.',
            ]);
        }
    }
}
