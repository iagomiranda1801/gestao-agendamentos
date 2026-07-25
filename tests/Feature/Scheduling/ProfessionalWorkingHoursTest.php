<?php

namespace Tests\Feature\Scheduling;

use App\Enums\Weekday;
use App\Models\Professional;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\ProfessionalWorkingHoursService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class ProfessionalWorkingHoursTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_valid_working_hour_can_be_created(): void
    {
        $setup = $this->createBookableSetup();

        $hour = app(ProfessionalWorkingHoursService::class)->create(
            $setup['company'],
            $setup['professional'],
            [
                'weekday' => Weekday::Sunday->value,
                'start_time' => '10:00:00',
                'end_time' => '16:00:00',
            ],
        );

        $this->assertDatabaseHas('professional_working_hours', [
            'id' => $hour->getKey(),
            'professional_id' => $setup['professional']->getKey(),
        ]);
    }

    public function test_overlapping_working_hours_are_rejected(): void
    {
        $setup = $this->createBookableSetup();

        $this->expectException(ValidationException::class);

        app(ProfessionalWorkingHoursService::class)->create(
            $setup['company'],
            $setup['professional'],
            [
                'weekday' => Weekday::Monday->value,
                'start_time' => '10:00:00',
                'end_time' => '14:00:00',
            ],
        );
    }

    public function test_working_hour_for_other_company_professional_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $otherProfessional = Professional::factory()->create();

        $this->expectException(HttpException::class);

        app(ProfessionalWorkingHoursService::class)->create(
            $setup['company'],
            $otherProfessional,
            [
                'weekday' => Weekday::Monday->value,
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
            ],
        );
    }

    public function test_valid_until_before_valid_from_is_rejected(): void
    {
        $setup = $this->createBookableSetup();

        $this->expectException(ValidationException::class);

        app(ProfessionalWorkingHoursService::class)->create(
            $setup['company'],
            $setup['professional'],
            [
                'weekday' => Weekday::Sunday->value,
                'start_time' => '09:00:00',
                'end_time' => '12:00:00',
                'valid_from' => '2026-12-01',
                'valid_until' => '2026-11-01',
            ],
        );
    }

    public function test_professional_without_working_hours_has_no_slots(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $professional = Professional::factory()->forCompany($company)->bookable()->active()->create();
        $service = $setup['service'];

        $this->seedStandardBusinessHours($company);

        $slots = app(AvailabilityService::class)->getAvailableSlots(
            $company,
            $professional,
            $service,
            $setup['localStart']->startOfDay(),
        );

        $this->assertTrue($slots->isEmpty());
    }
}
