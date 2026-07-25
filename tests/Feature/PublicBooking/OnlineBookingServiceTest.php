<?php

namespace Tests\Feature\PublicBooking;

use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentPublicAccessToken;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\PublicBooking\PublicAppointmentTokenService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class OnlineBookingServiceTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    public function test_online_appointment_is_created(): void
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

        $this->assertSame(AppointmentOrigin::Online, $result->appointment->origin);
        $this->assertSame(AppointmentStatus::Pending, $result->appointment->status);
        $this->assertNotNull($result->plainToken);
        $this->assertNotNull($result->manageUrl);
        $this->assertDatabaseHas('appointments', [
            'id' => $result->appointment->getKey(),
            'company_id' => $setup['company']->getKey(),
            'origin' => AppointmentOrigin::Online->value,
        ]);
    }

    public function test_idempotent_requests_return_same_appointment(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $idempotencyUuid = (string) Str::uuid();
        $data = $this->makeOnlineBookingData(
            $setup['company'],
            $setup['service']->getKey(),
            $setup['professional']->getKey(),
            $setup['localStart'],
            ['idempotencyUuid' => $idempotencyUuid],
        );

        $first = app(OnlineBookingService::class)->create($data);
        $second = app(OnlineBookingService::class)->create($data);

        $this->assertSame($first->appointment->getKey(), $second->appointment->getKey());
        $this->assertSame(1, Appointment::query()->where('company_id', $setup['company']->getKey())->count());
        $this->assertNull($second->plainToken);
    }

    public function test_auto_confirm_creates_confirmed_appointment(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company'], [
            'online_auto_confirm' => true,
        ]);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        $this->assertSame(AppointmentStatus::Confirmed, $result->appointment->status);

        $this->assertDatabaseHas('appointment_histories', [
            'appointment_id' => $result->appointment->getKey(),
            'type' => AppointmentHistoryType::Confirmed->value,
        ]);
    }

    public function test_snapshots_are_stored_on_creation(): void
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

        $appointment = $result->appointment;

        $this->assertSame($setup['service']->name, $appointment->service_name_snapshot);
        $this->assertSame((string) $setup['service']->price, (string) $appointment->price_snapshot);
        $this->assertSame(60, $appointment->duration_minutes_snapshot);
        $this->assertSame('Maria Silva', $appointment->client_name_snapshot);
    }

    public function test_only_token_hash_is_persisted(): void
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

        $plainToken = $result->plainToken;
        $expectedHash = app(PublicAppointmentTokenService::class)->hashToken($plainToken);

        $storedToken = AppointmentPublicAccessToken::query()
            ->where('appointment_id', $result->appointment->getKey())
            ->first();

        $this->assertNotNull($storedToken);
        $this->assertSame($expectedHash, $storedToken->token_hash);
        $this->assertNotSame($plainToken, $storedToken->token_hash);
        $this->assertDatabaseMissing('appointment_public_access_tokens', [
            'token_hash' => $plainToken,
        ]);
    }

    public function test_created_history_is_recorded(): void
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

        $this->assertDatabaseHas('appointment_histories', [
            'appointment_id' => $result->appointment->getKey(),
            'type' => AppointmentHistoryType::Created->value,
        ]);
    }

    public function test_overlapping_slot_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );

        $this->expectException(ValidationException::class);

        app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
                [
                    'clientPhone' => '(11) 91234-5678',
                    'idempotencyUuid' => (string) Str::uuid(),
                ],
            ),
        );
    }

    public function test_sequential_attempts_on_same_slot_create_only_one_appointment(): void
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company']);

        $service = app(OnlineBookingService::class);
        $firstException = null;

        try {
            $service->create($this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
                ['clientPhone' => '(11) 91111-1111'],
            ));
        } catch (ValidationException $exception) {
            $firstException = $exception;
        }

        try {
            $service->create($this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
                ['clientPhone' => '(11) 92222-2222'],
            ));
        } catch (ValidationException $exception) {
            $this->assertNull($firstException);
        }

        $this->assertSame(1, Appointment::query()
            ->where('company_id', $setup['company']->getKey())
            ->where('origin', AppointmentOrigin::Online->value)
            ->count());
    }

    public function test_service_from_another_company_is_rejected(): void
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

    public function test_disabled_public_booking_aborts(): void
    {
        $setup = $this->createBookableSetup();

        $this->expectException(NotFoundHttpException::class);

        app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );
    }

    public function test_inactive_company_aborts(): void
    {
        $company = $this->createSchedulingCompany(['is_active' => false]);
        $setup = $this->createBookableSetup($company);
        $this->enablePublicBooking($company);

        $this->expectException(NotFoundHttpException::class);

        app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $company,
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
            ),
        );
    }
}
