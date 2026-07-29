<?php

namespace Tests\Feature\Scheduling;

use App\Enums\AppointmentStatus;
use App\Enums\CompanyRole;
use App\Enums\ScheduleBlockType;
use App\Models\Professional;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\ScheduleBlockService;
use Carbon\CarbonImmutable;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class CalendarPageTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_calendar_loads_events_for_visible_period_only(): void
    {
        $setup = $this->createBookableSetup();
        $admin = $setup['admin'];

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $admin,
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $admin,
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart']->addWeeks(3),
        );

        $this->authenticateForAppTenant($admin, $setup['company']);

        $visibleStart = $setup['localStart']->startOfDay()->utc();
        $visibleEnd = $setup['localStart']->endOfDay()->utc();

        $events = app(AppointmentService::class)->fetchCalendarEvents(
            $setup['company'],
            $admin,
            CarbonImmutable::parse($visibleStart),
            CarbonImmutable::parse($visibleEnd),
        );

        $this->assertCount(1, $events);
    }

    public function test_calendar_loads_schedule_blocks(): void
    {
        $setup = $this->createBookableSetup();

        app(ScheduleBlockService::class)->create($setup['company'], $setup['admin'], [
            'type' => ScheduleBlockType::Manual,
            'title' => 'Reunião interna',
            'professional_id' => $setup['professional']->getKey(),
            'start_date' => $setup['localStart']->toDateString(),
            'start_time' => '09:30',
            'end_date' => $setup['localStart']->toDateString(),
            'end_time' => '10:30',
        ]);

        $events = app(AppointmentService::class)->fetchCalendarEvents(
            $setup['company'],
            $setup['admin'],
            $setup['localStart']->startOfDay()->utc(),
            $setup['localStart']->endOfDay()->utc(),
        );

        $this->assertCount(1, $events);
        $this->assertSame('schedule_block', $events[0]['extendedProps']['type']);
        $this->assertStringStartsWith('Bloqueio: Reunião interna', $events[0]['title']);
        $this->assertArrayNotHasKey('viewUrl', $events[0]['extendedProps']);
    }

    public function test_calendar_block_filter_includes_company_wide_blocks(): void
    {
        $setup = $this->createBookableSetup();

        app(ScheduleBlockService::class)->create($setup['company'], $setup['admin'], [
            'type' => ScheduleBlockType::Manual,
            'title' => 'Feriado',
            'start_date' => $setup['localStart']->toDateString(),
            'start_time' => '09:30',
            'end_date' => $setup['localStart']->toDateString(),
            'end_time' => '10:30',
        ]);

        $events = app(AppointmentService::class)->fetchCalendarEvents(
            $setup['company'],
            $setup['admin'],
            $setup['localStart']->startOfDay()->utc(),
            $setup['localStart']->endOfDay()->utc(),
            ['professional_id' => $setup['professional']->getKey()],
        );

        $this->assertCount(1, $events);
        $this->assertSame('Toda a empresa', $events[0]['extendedProps']['professional']);
    }

    public function test_professional_filter_works(): void
    {
        $setup = $this->createBookableSetup();
        $otherProfessional = Professional::factory()->forCompany($setup['company'])->bookable()->active()->create();
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

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $otherProfessional,
            $setup['service'],
            $setup['localStart']->addHours(2),
        );

        $visibleStart = $setup['localStart']->startOfDay()->utc();
        $visibleEnd = $setup['localStart']->endOfDay()->addDay()->utc();

        $events = app(AppointmentService::class)->fetchCalendarEvents(
            $setup['company'],
            $setup['admin'],
            CarbonImmutable::parse($visibleStart),
            CarbonImmutable::parse($visibleEnd),
            ['professional_id' => $setup['professional']->getKey()],
        );

        $this->assertCount(1, $events);
    }

    public function test_cancelled_events_have_grey_color(): void
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

        $events = app(AppointmentService::class)->fetchCalendarEvents(
            $setup['company'],
            $setup['admin'],
            $setup['localStart']->startOfDay()->utc(),
            $setup['localStart']->endOfDay()->utc(),
        );

        $this->assertSame('#9ca3af', $events[0]['backgroundColor']);
    }

    public function test_employee_calendar_payload_excludes_internal_notes(): void
    {
        $setup = $this->createBookableSetup();
        $employee = $this->createCompanyUser($setup['company'], [], CompanyRole::Employee);

        $linkedProfessional = Professional::factory()
            ->forCompany($setup['company'])
            ->linkedToUser($employee)
            ->bookable()
            ->active()
            ->create();

        $this->seedStandardWorkingHours($setup['company'], $linkedProfessional);
        $linkedProfessional->services()->attach($setup['service']->getKey(), [
            'company_id' => $setup['company']->getKey(),
            'is_active' => true,
        ]);

        app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $linkedProfessional,
            $setup['service'],
            $setup['localStart'],
            ['internal_notes' => 'Segredo interno'],
        );

        $events = app(AppointmentService::class)->fetchCalendarEvents(
            $setup['company'],
            $employee,
            $setup['localStart']->startOfDay()->utc(),
            $setup['localStart']->endOfDay()->utc(),
        );

        $this->assertArrayNotHasKey('internal_notes', $events[0]['extendedProps'] ?? []);
    }

    public function test_agenda_page_is_accessible_for_admin(): void
    {
        $setup = $this->createBookableSetup();

        $this->authenticateForAppTenant($setup['admin'], $setup['company']);

        $this->get(route('filament.app.pages.agenda', ['tenant' => $setup['company']]))
            ->assertSuccessful();
    }
}
