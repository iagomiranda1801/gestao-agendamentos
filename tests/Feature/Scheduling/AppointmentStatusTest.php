<?php

namespace Tests\Feature\Scheduling;

use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentChangeEmailJob;
use App\Jobs\SendAppointmentChangeWhatsAppJob;
use App\Models\Appointment;
use App\Models\AppointmentHistory;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AppointmentStatusService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class AppointmentStatusTest extends TestCase
{
    use CreatesSchedulingFixtures;

    protected function createPendingAppointment(array $setup): Appointment
    {
        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $appointment->update([
            'status' => AppointmentStatus::Pending,
            'confirmed_by' => null,
            'confirmed_at' => null,
        ]);

        return $appointment->refresh();
    }

    public function test_pending_can_be_confirmed(): void
    {
        $setup = $this->createBookableSetup();
        $appointment = $this->createPendingAppointment($setup);

        $confirmed = app(AppointmentStatusService::class)->confirm(
            $setup['company'],
            $setup['admin'],
            $appointment,
        );

        $this->assertSame(AppointmentStatus::Confirmed, $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
    }

    public function test_confirmed_can_be_started(): void
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

        $started = app(AppointmentStatusService::class)->start(
            $setup['company'],
            $setup['admin'],
            $appointment,
        );

        $this->assertSame(AppointmentStatus::InProgress, $started->status);
    }

    public function test_cancel_requires_reason(): void
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

        $this->expectException(ValidationException::class);

        app(AppointmentStatusService::class)->cancel(
            $setup['company'],
            $setup['admin'],
            $appointment,
            '',
        );
    }

    public function test_cancel_records_user_and_timestamp(): void
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

        $cancelled = app(AppointmentStatusService::class)->cancel(
            $setup['company'],
            $setup['admin'],
            $appointment,
            'Cliente desistiu',
        );

        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->status);
        $this->assertSame($setup['admin']->getKey(), $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        Queue::assertPushed(SendAppointmentChangeWhatsAppJob::class);
        Queue::assertPushed(SendAppointmentChangeEmailJob::class);
    }

    public function test_no_show_only_after_scheduled_time(): void
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

        $this->expectException(ValidationException::class);

        app(AppointmentStatusService::class)->markNoShow(
            $setup['company'],
            $setup['admin'],
            $appointment,
        );
    }

    public function test_cancelled_cannot_be_confirmed(): void
    {
        $setup = $this->createBookableSetup();
        $appointment = $this->createPendingAppointment($setup);

        app(AppointmentStatusService::class)->cancel(
            $setup['company'],
            $setup['admin'],
            $appointment,
            'Motivo',
        );

        $this->expectException(ValidationException::class);

        app(AppointmentStatusService::class)->confirm(
            $setup['company'],
            $setup['admin'],
            $appointment->refresh(),
        );
    }

    public function test_history_is_immutable(): void
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

        $history = AppointmentHistory::query()->firstOrFail();

        $this->assertFalse($setup['admin']->can('update', $history));
        $this->assertFalse($setup['admin']->can('delete', $history));
    }
}
