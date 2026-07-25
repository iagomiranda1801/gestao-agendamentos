<?php

namespace Tests\Concerns;

use App\DataTransferObjects\PublicBooking\OnlineBookingData;
use App\Models\Company;
use App\Models\CompanySchedulingSetting;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

trait CreatesPublicBookingFixtures
{
    protected function enablePublicBooking(Company $company, array $overrides = []): CompanySchedulingSetting
    {
        app(CompanySchedulingSettingService::class)->update($company, array_merge([
            'public_booking_enabled' => true,
            'online_auto_confirm' => false,
            'require_email_for_online_booking' => false,
            'allow_public_cancellation' => true,
            'allow_public_reschedule' => true,
            'allow_professional_selection' => true,
            'allow_no_professional_preference' => false,
            'show_service_price' => true,
            'show_service_duration' => true,
            'minimum_advance_minutes' => 0,
            'maximum_advance_days' => 60,
            'cancellation_minimum_advance_minutes' => 0,
            'reschedule_minimum_advance_minutes' => 0,
        ], $overrides));

        return app(CompanySchedulingSettingService::class)->getOrCreate($company)->refresh();
    }

    protected function makeOnlineBookingData(
        Company $company,
        int $serviceId,
        ?int $professionalId,
        CarbonImmutable $localStart,
        array $overrides = [],
    ): OnlineBookingData {
        return new OnlineBookingData(
            company: $company,
            serviceId: $serviceId,
            professionalId: $professionalId,
            localStart: $localStart,
            clientName: $overrides['clientName'] ?? 'Maria Silva',
            clientPhone: $overrides['clientPhone'] ?? '(11) 98765-4321',
            clientEmail: $overrides['clientEmail'] ?? null,
            notes: $overrides['notes'] ?? null,
            idempotencyUuid: $overrides['idempotencyUuid'] ?? (string) Str::uuid(),
            privacyAccepted: $overrides['privacyAccepted'] ?? false,
            termsAccepted: $overrides['termsAccepted'] ?? false,
            honeypot: $overrides['honeypot'] ?? null,
            formStartedAt: $overrides['formStartedAt'] ?? CompanyDateTime::nowUtc()->subSeconds(10),
            clientDocument: $overrides['clientDocument'] ?? null,
        );
    }
}
