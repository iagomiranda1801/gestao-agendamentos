<?php

namespace Tests\Feature\PublicBooking;

use App\Livewire\PublicBooking\BookingWizard;
use App\Models\Appointment;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\PublicBooking\PublicBookingRateLimiter;
use App\Support\CompanyDateTime;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class OnlineBookingSecurityTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_honeypot_rejects_booking_attempt(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $this->expectException(ValidationException::class);

        app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
                ['honeypot' => 'bot-filled-this'],
            ),
        );
    }

    public function test_form_submitted_too_quickly_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $this->expectException(ValidationException::class);

        app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
                ['formStartedAt' => CompanyDateTime::nowUtc()],
            ),
        );
    }

    public function test_livewire_honeypot_shows_fake_confirmation(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);
        $localStart = $setup['localStart'];

        Livewire::test(BookingWizard::class, ['company' => $setup['company']])
            ->set('serviceId', $setup['service']->getKey())
            ->set('professionalSelection', $setup['professional']->getKey())
            ->set('selectedDate', $localStart->format('Y-m-d'))
            ->set('selectedTime', $localStart->format('H:i'))
            ->set('clientName', 'Maria Silva')
            ->set('clientPhone', '(11) 98765-4321')
            ->set('privacyAccepted', true)
            ->set('step', BookingWizard::STEP_REVIEW)
            ->set('website_url', 'https://spam.example')
            ->call('confirmBooking')
            ->assertSet('step', BookingWizard::STEP_CONFIRMATION)
            ->assertSet('confirmationCode', '------');

        $this->assertSame(0, Appointment::query()->where('company_id', $setup['company']->getKey())->count());
    }

    public function test_create_rate_limit_blocks_excessive_ip_attempts(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);
        $rateLimiter = app(PublicBookingRateLimiter::class);
        $ip = '203.0.113.10';

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $rateLimiter->assertCreateAttemptAllowed(
                (int) $setup['company']->getKey(),
                $ip,
                null,
            );
        }

        $this->expectException(ValidationException::class);

        $rateLimiter->assertCreateAttemptAllowed(
            (int) $setup['company']->getKey(),
            $ip,
            null,
        );
    }

    public function test_availability_rate_limit_blocks_excessive_checks(): void
    {
        $setup = $this->createBookableSetup();
        $rateLimiter = app(PublicBookingRateLimiter::class);
        $ip = '203.0.113.20';

        for ($attempt = 0; $attempt < 60; $attempt++) {
            $rateLimiter->assertAvailabilityCheckAllowed(
                (int) $setup['company']->getKey(),
                $ip,
            );
        }

        $this->expectException(ValidationException::class);

        $rateLimiter->assertAvailabilityCheckAllowed(
            (int) $setup['company']->getKey(),
            $ip,
        );
    }

    public function test_booking_cannot_use_service_from_another_company(): void
    {
        $setup = $this->createBookableSetup();
        $otherSetup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $this->expectException(ValidationException::class);

        app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $otherSetup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );
    }

    public function test_booking_cannot_use_professional_from_another_company(): void
    {
        $setup = $this->createBookableSetup();
        $otherSetup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $this->expectException(ValidationException::class);

        app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $otherSetup['professional']->getKey(),
                $setup['localStart'],
            ),
        );
    }

    public function test_appointment_is_always_created_in_submitted_company(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        $this->assertSame($setup['company']->getKey(), $result->appointment->company_id);
        $this->assertSame($setup['professional']->getKey(), $result->appointment->professional_id);
        $this->assertSame($setup['service']->getKey(), $result->appointment->service_id);
    }
}
