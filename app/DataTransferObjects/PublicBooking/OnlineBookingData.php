<?php

namespace App\DataTransferObjects\PublicBooking;

use App\Models\Company;
use Carbon\CarbonImmutable;

readonly class OnlineBookingData
{
    public function __construct(
        public Company $company,
        public int $serviceId,
        public ?int $professionalId,
        public CarbonImmutable $localStart,
        public string $clientName,
        public string $clientPhone,
        public ?string $clientEmail,
        public ?string $notes,
        public string $idempotencyUuid,
        public bool $privacyAccepted,
        public bool $termsAccepted,
        public ?string $honeypot,
        public CarbonImmutable $formStartedAt,
        public ?string $clientDocument = null,
    ) {}
}
