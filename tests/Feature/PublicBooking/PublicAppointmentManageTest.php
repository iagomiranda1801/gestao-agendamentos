<?php

namespace Tests\Feature\PublicBooking;

use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentStatus;
use App\Jobs\SendAppointmentChangeEmailJob;
use App\Jobs\SendAppointmentChangeWhatsAppJob;
use App\Livewire\PublicBooking\ManageAppointment;
use App\Services\PublicBooking\OnlineBookingService;
use App\Services\PublicBooking\PublicAppointmentService;
use App\Support\CompanyDateTime;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\CreatesPublicBookingFixtures;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class PublicAppointmentManageTest extends TestCase
{
    use CreatesPublicBookingFixtures;
    use CreatesSchedulingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * @return array<string, mixed>
     */
    protected function createOnlineAppointment(array $bookingOverrides = [], array $settingOverrides = []): array
    {
        $setup = $this->createBookableSetup();
        $this->enablePublicBooking($setup['company'], $settingOverrides);

        $result = app(OnlineBookingService::class)->create(
            $this->makeOnlineBookingData(
                $setup['company'],
                $setup['service']->getKey(),
                $setup['professional']->getKey(),
                $setup['localStart'],
                $bookingOverrides,
            ),
        );

        return array_merge($setup, [
            'appointment' => $result->appointment,
            'plainToken' => $result->plainToken,
        ]);
    }

    public function test_manage_page_loads_with_valid_token(): void
    {
        $context = $this->createOnlineAppointment();

        $this->get(route('public.appointment.manage', ['token' => $context['plainToken']]))
            ->assertOk();
    }

    public function test_manage_page_returns_404_for_invalid_token(): void
    {
        $this->get(route('public.appointment.manage', ['token' => 'token-invalido']))
            ->assertNotFound();
    }

    public function test_public_cancel_updates_appointment_status(): void
    {
        Queue::fake();

        $context = $this->createOnlineAppointment();

        $appointment = app(PublicAppointmentService::class)->cancelPublic(
            $context['company'],
            $context['appointment'],
            'Não poderei comparecer',
        );

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->status);
        $this->assertSame('Não poderei comparecer', $appointment->cancellation_reason);
        $this->assertDatabaseHas('appointment_histories', [
            'appointment_id' => $appointment->getKey(),
            'type' => AppointmentHistoryType::Cancelled->value,
        ]);
        Queue::assertPushed(SendAppointmentChangeWhatsAppJob::class);
        Queue::assertPushed(SendAppointmentChangeEmailJob::class);
    }

    public function test_public_cancel_via_livewire(): void
    {
        $context = $this->createOnlineAppointment();

        Livewire::test(ManageAppointment::class, ['token' => $context['plainToken']])
            ->call('openCancelModal')
            ->set('cancelReason', 'Imprevisto de última hora')
            ->call('submitCancel')
            ->assertSet('successMessage', 'Agendamento cancelado com sucesso.');

        $this->assertSame(
            AppointmentStatus::Cancelled,
            $context['appointment']->refresh()->status,
        );
    }

    public function test_public_reschedule_updates_start_time(): void
    {
        Queue::fake();

        $context = $this->createOnlineAppointment();
        $newStart = $context['localStart']->addHours(2);

        $appointment = app(PublicAppointmentService::class)->reschedulePublic(
            $context['company'],
            $context['appointment'],
            $newStart,
        );

        $this->assertSame(
            $newStart->format('Y-m-d H:i'),
            CompanyDateTime::utcToLocal($context['company'], $appointment->start_at)->format('Y-m-d H:i'),
        );
        $this->assertDatabaseHas('appointment_histories', [
            'appointment_id' => $appointment->getKey(),
            'type' => AppointmentHistoryType::Rescheduled->value,
        ]);
        Queue::assertPushed(SendAppointmentChangeWhatsAppJob::class);
        Queue::assertPushed(SendAppointmentChangeEmailJob::class);
    }

    public function test_public_reschedule_via_livewire(): void
    {
        $context = $this->createOnlineAppointment();
        $newStart = $context['localStart']->addHours(2);

        Livewire::test(ManageAppointment::class, ['token' => $context['plainToken']])
            ->call('openRescheduleModal')
            ->set('rescheduleDate', $newStart->format('Y-m-d'))
            ->set('rescheduleTime', $newStart->format('H:i'))
            ->call('submitReschedule')
            ->assertSet('successMessage', 'Agendamento reagendado com sucesso.');

        $this->assertSame(
            $newStart->format('Y-m-d H:i'),
            CompanyDateTime::utcToLocal(
                $context['company'],
                $context['appointment']->refresh()->start_at,
            )->format('Y-m-d H:i'),
        );
    }

    public function test_cancellation_is_blocked_when_not_allowed(): void
    {
        $context = $this->createOnlineAppointment([], [
            'allow_public_cancellation' => false,
        ]);

        $this->expectException(ValidationException::class);

        app(PublicAppointmentService::class)->cancelPublic(
            $context['company'],
            $context['appointment'],
            'Motivo válido',
        );
    }

    public function test_reschedule_is_blocked_when_not_allowed(): void
    {
        $context = $this->createOnlineAppointment([], [
            'allow_public_reschedule' => false,
        ]);

        $this->expectException(ValidationException::class);

        app(PublicAppointmentService::class)->reschedulePublic(
            $context['company'],
            $context['appointment'],
            $context['localStart']->addHours(2),
        );
    }

    public function test_cancellation_deadline_is_enforced(): void
    {
        $context = $this->createOnlineAppointment([], [
            'cancellation_minimum_advance_minutes' => 120,
        ]);

        $this->travelTo($context['localStart']->subMinutes(30)->utc());

        $this->expectException(ValidationException::class);

        app(PublicAppointmentService::class)->cancelPublic(
            $context['company'],
            $context['appointment']->refresh(),
            'Tarde demais',
        );
    }

    public function test_reschedule_deadline_is_enforced(): void
    {
        $context = $this->createOnlineAppointment([], [
            'reschedule_minimum_advance_minutes' => 120,
        ]);

        $this->travelTo($context['localStart']->subMinutes(30)->utc());

        $this->expectException(ValidationException::class);

        app(PublicAppointmentService::class)->reschedulePublic(
            $context['company'],
            $context['appointment']->refresh(),
            $context['localStart']->addHours(2),
        );
    }

    public function test_manage_page_explains_when_public_actions_are_unavailable(): void
    {
        $context = $this->createOnlineAppointment([], [
            'cancellation_minimum_advance_minutes' => 120,
            'reschedule_minimum_advance_minutes' => 120,
        ]);

        $this->travelTo($context['localStart']->subMinutes(30)->utc());

        Livewire::test(ManageAppointment::class, ['token' => $context['plainToken']])
            ->assertSee('Alterações online indisponíveis.')
            ->assertSee('O prazo para cancelamento online expirou.')
            ->assertSee('O prazo para remarcação online expirou.');
    }

    public function test_cancelled_appointment_is_view_only_in_livewire(): void
    {
        $context = $this->createOnlineAppointment();

        app(PublicAppointmentService::class)->cancelPublic(
            $context['company'],
            $context['appointment'],
            'Cancelamento inicial',
        );

        $component = Livewire::test(ManageAppointment::class, ['token' => $context['plainToken']]);

        $component
            ->call('openCancelModal')
            ->assertSet('showCancelModal', false);

        $this->assertFalse($component->instance()->canCancel());
        $this->assertTrue($component->instance()->isViewOnly());
    }

    public function test_cancel_requires_reason(): void
    {
        $context = $this->createOnlineAppointment();

        $this->expectException(ValidationException::class);

        app(PublicAppointmentService::class)->cancelPublic(
            $context['company'],
            $context['appointment'],
            '',
        );
    }
}
