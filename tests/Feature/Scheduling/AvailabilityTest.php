<?php

namespace Tests\Feature\Scheduling;

use App\Enums\ScheduleBlockType;
use App\Models\Client;
use App\Models\Professional;
use App\Models\Service;
use App\Services\Scheduling\AppointmentService;
use App\Services\Scheduling\AppointmentSnapshotResolver;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\ScheduleBlockService;
use App\Support\CompanyDateTime;
use Database\Factories\ProfessionalServiceFactory;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_time_within_working_hours_is_available(): void
    {
        $setup = $this->createBookableSetup();

        $result = app(AvailabilityService::class)->assertAvailable(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
            60,
            0,
            0,
        );

        $this->assertTrue($result->available);
    }

    public function test_time_outside_business_hours_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $localStart = $setup['localStart']->setTime(7, 0);

        $result = app(AvailabilityService::class)->assertAvailable(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
            $localStart,
            60,
            0,
            0,
        );

        $this->assertFalse($result->available);
    }

    public function test_time_in_past_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $localStart = CompanyDateTime::nowLocal($setup['company'])->subHour();

        $result = app(AvailabilityService::class)->assertAvailable(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
            $localStart,
            60,
            0,
            0,
        );

        $this->assertFalse($result->available);
    }

    public function test_inactive_professional_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $setup['professional']->update(['is_active' => false]);

        $result = app(AvailabilityService::class)->assertAvailable(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
            60,
            0,
            0,
        );

        $this->assertFalse($result->available);
    }

    public function test_non_bookable_service_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $setup['service']->update(['is_bookable' => false]);

        $result = app(AvailabilityService::class)->assertAvailable(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
            60,
            0,
            0,
        );

        $this->assertFalse($result->available);
    }

    public function test_misaligned_slot_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $localStart = $setup['localStart']->setTime(10, 7);

        $result = app(AvailabilityService::class)->assertAvailable(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
            $localStart,
            60,
            0,
            0,
        );

        $this->assertFalse($result->available);
    }

    public function test_custom_duration_from_professional_service_is_respected(): void
    {
        $setup = $this->createBookableSetup();

        $setup['professional']->services()->updateExistingPivot($setup['service']->getKey(), [
            'custom_duration_minutes' => 90,
        ]);

        $snapshots = app(AppointmentSnapshotResolver::class)->resolve(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
        );

        $this->assertSame(90, $snapshots['duration_minutes_snapshot']);
    }

    public function test_buffer_before_blocks_adjacent_appointment(): void
    {
        $setup = $this->createBookableSetup();
        $setup['service']->update(['buffer_before_minutes' => 15]);

        $first = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $secondStart = $setup['localStart']->addMinutes(60);

        $result = app(AvailabilityService::class)->assertAvailable(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
            $secondStart,
            60,
            15,
            0,
        );

        $this->assertFalse($result->available);
    }

    public function test_adjacent_appointments_without_buffer_are_allowed(): void
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

        $secondStart = $setup['localStart']->addMinutes(60);

        $result = app(AvailabilityService::class)->assertAvailable(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
            $secondStart,
            60,
            0,
            0,
        );

        $this->assertTrue($result->available);
    }

    public function test_schedule_block_removes_availability(): void
    {
        $setup = $this->createBookableSetup();

        app(ScheduleBlockService::class)->create($setup['company'], $setup['admin'], [
            'type' => ScheduleBlockType::Manual,
            'title' => 'Bloqueio',
            'professional_id' => $setup['professional']->getKey(),
            'start_date' => $setup['localStart']->format('Y-m-d'),
            'start_time' => '09:30',
            'end_date' => $setup['localStart']->format('Y-m-d'),
            'end_time' => '11:30',
        ]);

        $result = app(AvailabilityService::class)->assertAvailable(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
            60,
            0,
            0,
        );

        $this->assertFalse($result->available);
    }

    public function test_all_day_schedule_block_can_be_created_without_times(): void
    {
        $setup = $this->createBookableSetup();

        $block = app(ScheduleBlockService::class)->create($setup['company'], $setup['admin'], [
            'type' => ScheduleBlockType::Manual,
            'title' => 'Bloqueio dia inteiro',
            'is_all_day' => true,
            'start_date' => $setup['localStart']->toDateString(),
            'end_date' => $setup['localStart']->toDateString(),
        ]);

        $this->assertTrue($block->is_all_day);
        $this->assertSame('00:01', CompanyDateTime::utcToLocal($setup['company'], $block->start_at)->format('H:i'));
        $this->assertSame('23:59', CompanyDateTime::utcToLocal($setup['company'], $block->end_at)->format('H:i'));
    }

    public function test_different_timezone_company_works_correctly(): void
    {
        $company = $this->createSchedulingCompany(['timezone' => 'America/Manaus']);
        $admin = $this->createCompanyUser($company);
        $client = Client::factory()->forCompany($company)->active()->create();
        $professional = Professional::factory()->forCompany($company)->bookable()->active()->create();
        $service = Service::factory()->forCompany($company)->bookable()->active()->create(['duration_minutes' => 60]);

        ProfessionalServiceFactory::new()->forCompany($company)->create([
            'professional_id' => $professional->getKey(),
            'service_id' => $service->getKey(),
        ]);

        $this->seedStandardScheduling($company, $professional);
        $localStart = $this->nextWeekdayAt($company, 10, 0);

        $result = app(AvailabilityService::class)->assertAvailable(
            $company,
            $professional,
            $service,
            $localStart,
            60,
            0,
            0,
        );

        $this->assertTrue($result->available);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $company,
            $admin,
            $client,
            $professional,
            $service,
            $localStart,
        );

        $this->assertSame(
            $localStart->format('Y-m-d H:i'),
            CompanyDateTime::utcToLocal($company, $appointment->start_at)->format('Y-m-d H:i'),
        );
    }
}
