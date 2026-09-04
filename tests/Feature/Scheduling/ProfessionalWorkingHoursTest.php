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

    public function test_create_many_adds_weekdays_without_removing_existing_hours(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $professional = Professional::factory()->forCompany($company)->bookable()->active()->create();
        $service = app(ProfessionalWorkingHoursService::class);

        $saturday = $service->create($company, $professional, [
            'weekday' => Weekday::Saturday->value,
            'start_time' => '09:00:00',
            'end_time' => '12:00:00',
        ]);

        $created = $service->createMany($company, $professional, $this->weekdayHours());

        $this->assertCount(5, $created);
        $this->assertDatabaseHas('professional_working_hours', [
            'id' => $saturday->getKey(),
            'professional_id' => $professional->getKey(),
            'weekday' => Weekday::Saturday->value,
        ]);
        $this->assertSame(6, $professional->workingHours()->count());
        $this->assertEqualsCanonicalizing(
            [
                Weekday::Monday->value,
                Weekday::Tuesday->value,
                Weekday::Wednesday->value,
                Weekday::Thursday->value,
                Weekday::Friday->value,
                Weekday::Saturday->value,
            ],
            $professional->workingHours()->pluck('weekday')->all(),
        );
    }

    public function test_create_many_rejects_overlapping_hours_on_the_same_day(): void
    {
        $setup = $this->createBookableSetup();

        try {
            app(ProfessionalWorkingHoursService::class)->createMany(
                $setup['company'],
                $setup['professional'],
                [
                    [
                        'weekday' => Weekday::Monday->value,
                        'start_time' => '10:00:00',
                        'end_time' => '14:00:00',
                    ],
                ],
            );

            $this->fail('Expected overlapping hours to be rejected.');
        } catch (ValidationException $exception) {
            $messages = collect($exception->errors())->flatten()->implode(' ');

            $this->assertStringContainsString('Segunda-feira', $messages);
            $this->assertStringContainsString('sobrepostas', $messages);
        }
    }

    /**
     * @return list<array{weekday: int, start_time: string, end_time: string}>
     */
    protected function weekdayHours(string $start = '09:00:00', string $end = '18:00:00'): array
    {
        return collect([
            Weekday::Monday,
            Weekday::Tuesday,
            Weekday::Wednesday,
            Weekday::Thursday,
            Weekday::Friday,
        ])->map(fn (Weekday $day): array => [
            'weekday' => $day->value,
            'start_time' => $start,
            'end_time' => $end,
        ])->all();
    }
}
