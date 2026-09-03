<?php

namespace Tests\Feature\Scheduling;

use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentOrigin;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentChangeEmailJob;
use App\Jobs\SendAppointmentChangeWhatsAppJob;
use App\Jobs\SendAppointmentCreatedEmailJob;
use App\Jobs\SendWhatsAppAppointmentConfirmationJob;
use App\Models\InventoryBalance;
use App\Models\Professional;
use App\Models\StockMovement;
use App\Services\Scheduling\AppointmentService;
use App\Support\CompanyDateTime;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class AppointmentServiceTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_internal_appointment_is_created_confirmed(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame(AppointmentOrigin::Internal, $appointment->origin);
    }

    public function test_internal_appointment_queues_whatsapp_and_email_notifications(): void
    {
        Queue::fake();
        $setup = $this->createBookableSetup();

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        Queue::assertPushed(SendWhatsAppAppointmentConfirmationJob::class);
        Queue::assertPushed(SendAppointmentCreatedEmailJob::class);
    }

    public function test_end_at_is_calculated_by_backend(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $expectedEnd = CompanyDateTime::localToUtc(
            $setup['company'],
            $setup['localStart']->addMinutes(60),
        );

        $this->assertSame(
            $expectedEnd->format('Y-m-d H:i'),
            $appointment->end_at->format('Y-m-d H:i'),
        );
    }

    public function test_snapshots_are_stored_on_creation(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertSame($setup['service']->name, $appointment->service_name_snapshot);
        $this->assertSame((string) $setup['service']->price, (string) $appointment->price_snapshot);
        $this->assertSame(60, $appointment->duration_minutes_snapshot);
    }

    public function test_created_history_is_recorded(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertDatabaseHas('appointment_histories', [
            'appointment_id' => $appointment->getKey(),
            'type' => AppointmentHistoryType::Created->value,
        ]);
    }

    public function test_service_change_does_not_alter_existing_snapshot(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $originalName = $appointment->service_name_snapshot;
        $setup['service']->update(['name' => 'Nome alterado']);

        $appointment->refresh();

        $this->assertSame($originalName, $appointment->service_name_snapshot);
    }

    public function test_unaligned_slot_is_rejected_on_appointment_time(): void
    {
        $setup = $this->createBookableSetup();
        $unalignedStart = $setup['localStart']->setTime(9, 10);

        try {
            app(AppointmentService::class)->createInternalAppointment(
                $setup['company'],
                $setup['admin'],
                $setup['client'],
                $setup['professional'],
                $setup['service'],
                $unalignedStart,
            );
            $this->fail('Expected ValidationException for unaligned slot.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('appointment_time', $exception->errors());
            $this->assertArrayHasKey('start_at', $exception->errors());
            $this->assertSame(
                'Horário não alinhado ao intervalo da agenda.',
                $exception->errors()['appointment_time'][0],
            );
        }
    }

    public function test_overlapping_appointments_are_rejected(): void
    {
        $setup = $this->createBookableSetup();

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->expectException(ValidationException::class);

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart']->addMinutes(30),
        );
    }

    public function test_same_time_for_different_professionals_is_allowed(): void
    {
        $setup = $this->createBookableSetup();
        $otherProfessional = Professional::factory()->forCompany($setup['company'])->bookable()->active()->create();

        $setup['professional']->services()->syncWithoutDetaching([
            $setup['service']->getKey() => [
                'company_id' => $setup['company']->getKey(),
                'is_active' => true,
            ],
        ]);

        $otherProfessional->services()->attach($setup['service']->getKey(), [
            'company_id' => $setup['company']->getKey(),
            'is_active' => true,
        ]);

        $this->seedStandardWorkingHours($setup['company'], $otherProfessional);

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $second = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $otherProfessional,
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertNotNull($second);
    }

    public function test_cancelled_appointment_does_not_block_time(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        $second = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertNotNull($second);
    }

    public function test_reschedule_keeps_duration(): void
    {
        Queue::fake();

        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $newStart = $setup['localStart']->addHours(2);

        $rescheduled = app(AppointmentService::class)->reschedule(
            $setup['company'],
            $setup['admin'],
            $appointment,
            $newStart,
        );

        $this->assertSame(60, $rescheduled->duration_minutes_snapshot);
        $this->assertDatabaseHas('appointment_histories', [
            'appointment_id' => $appointment->getKey(),
            'type' => AppointmentHistoryType::Rescheduled->value,
        ]);
        Queue::assertPushed(SendAppointmentChangeWhatsAppJob::class);
        Queue::assertPushed(SendAppointmentChangeEmailJob::class);
    }

    public function test_appointment_does_not_move_stock_or_financial(): void
    {
        $setup = $this->createBookableSetup();
        $movementsBefore = StockMovement::query()->count();
        $balancesBefore = InventoryBalance::query()->count();

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame($balancesBefore, InventoryBalance::query()->count());
    }

    public function test_internal_appointment_can_be_created_with_service_to_be_defined(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            null,
            $setup['localStart'],
            [
                'service_selection_mode' => 'to_be_defined',
                'duration_minutes_snapshot' => 45,
                'appointment_reason' => 'Avaliação inicial',
            ],
        );

        $this->assertNull($appointment->service_id);
        $this->assertSame('to_be_defined', $appointment->service_selection_mode);
        $this->assertSame('A definir no atendimento', $appointment->service_name_snapshot);
        $this->assertNull($appointment->price_snapshot);
        $this->assertSame(45, $appointment->duration_minutes_snapshot);
        $this->assertSame('Avaliação inicial', $appointment->appointment_reason);
    }

    public function test_empty_service_automatically_creates_an_open_appointment(): void
    {
        $setup = $this->createBookableSetup();

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            null,
            $setup['localStart'],
            ['duration_minutes_snapshot' => 30],
        );

        $this->assertTrue($appointment->hasServiceToBeDefined());
        $this->assertSame(30, $appointment->duration_minutes_snapshot);
    }

    public function test_open_appointment_can_be_rescheduled_to_any_bookable_professional(): void
    {
        $setup = $this->createBookableSetup();
        $otherProfessional = Professional::factory()->forCompany($setup['company'])->bookable()->active()->create();
        $this->seedStandardWorkingHours($setup['company'], $otherProfessional);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            null,
            $setup['localStart'],
            ['duration_minutes_snapshot' => 30],
        );

        $rescheduled = app(AppointmentService::class)->reschedule(
            $setup['company'],
            $setup['admin'],
            $appointment,
            $setup['localStart']->addHours(2),
            $otherProfessional,
        );

        $this->assertSame($otherProfessional->getKey(), $rescheduled->professional_id);
    }
}
