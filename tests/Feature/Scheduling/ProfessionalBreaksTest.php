<?php

namespace Tests\Feature\Scheduling;

use App\Enums\Weekday;
use App\Models\Professional;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\ProfessionalBreakService;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class ProfessionalBreaksTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_break_within_working_hours_is_accepted(): void
    {
        $setup = $this->createBookableSetup();

        $break = app(ProfessionalBreakService::class)->create(
            $setup['company'],
            $setup['professional'],
            [
                'name' => 'Almoço',
                'weekday' => Weekday::Monday->value,
                'start_time' => '12:00:00',
                'end_time' => '13:00:00',
            ],
        );

        $this->assertDatabaseHas('professional_breaks', ['id' => $break->getKey()]);
    }

    public function test_overlapping_breaks_are_rejected(): void
    {
        $setup = $this->createBookableSetup();

        app(ProfessionalBreakService::class)->create(
            $setup['company'],
            $setup['professional'],
            [
                'name' => 'Almoço',
                'weekday' => Weekday::Monday->value,
                'start_time' => '12:00:00',
                'end_time' => '13:00:00',
            ],
        );

        $this->expectException(ValidationException::class);

        app(ProfessionalBreakService::class)->create(
            $setup['company'],
            $setup['professional'],
            [
                'name' => 'Intervalo',
                'weekday' => Weekday::Monday->value,
                'start_time' => '12:30:00',
                'end_time' => '13:30:00',
            ],
        );
    }

    public function test_break_removes_available_slots(): void
    {
        $setup = $this->createBookableSetup();
        $localStart = $setup['localStart']->setTime(12, 0);

        app(ProfessionalBreakService::class)->create(
            $setup['company'],
            $setup['professional'],
            [
                'name' => 'Almoço',
                'weekday' => $localStart->dayOfWeek,
                'start_time' => '12:00:00',
                'end_time' => '13:00:00',
            ],
        );

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

    public function test_inactive_break_does_not_remove_slots(): void
    {
        $setup = $this->createBookableSetup();
        $localStart = $setup['localStart']->setTime(12, 0);

        app(ProfessionalBreakService::class)->create(
            $setup['company'],
            $setup['professional'],
            [
                'name' => 'Almoço',
                'weekday' => $localStart->dayOfWeek,
                'start_time' => '12:00:00',
                'end_time' => '13:00:00',
                'is_active' => false,
            ],
        );

        $result = app(AvailabilityService::class)->assertAvailable(
            $setup['company'],
            $setup['professional'],
            $setup['service'],
            $localStart,
            60,
            0,
            0,
        );

        $this->assertTrue($result->available);
    }

    public function test_break_for_other_company_is_rejected(): void
    {
        $setup = $this->createBookableSetup();
        $otherProfessional = Professional::factory()->create();

        $this->expectException(HttpException::class);

        app(ProfessionalBreakService::class)->create(
            $setup['company'],
            $otherProfessional,
            [
                'name' => 'Almoço',
                'weekday' => Weekday::Monday->value,
                'start_time' => '12:00:00',
                'end_time' => '13:00:00',
            ],
        );
    }
}
