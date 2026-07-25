<?php

namespace Tests\Feature\Scheduling;

use App\Enums\Weekday;
use App\Models\CompanyBusinessHour;
use App\Services\Scheduling\AvailabilityService;
use App\Services\Scheduling\CompanyBusinessHoursService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class CompanyBusinessHoursTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_can_create_valid_business_hour_range(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];

        app(CompanyBusinessHoursService::class)->replaceWeeklyHours($company, [
            ['weekday' => Weekday::Monday->value, 'start_time' => '08:00:00', 'end_time' => '12:00:00'],
        ]);

        $this->assertDatabaseHas('company_business_hours', [
            'company_id' => $company->getKey(),
            'weekday' => Weekday::Monday->value,
        ]);
    }

    public function test_can_create_two_ranges_on_same_day(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];

        app(CompanyBusinessHoursService::class)->replaceWeeklyHours($company, [
            ['weekday' => Weekday::Monday->value, 'start_time' => '08:00:00', 'end_time' => '12:00:00'],
            ['weekday' => Weekday::Monday->value, 'start_time' => '13:00:00', 'end_time' => '18:00:00'],
        ]);

        $this->assertSame(2, CompanyBusinessHour::query()->where('company_id', $company->getKey())->count());
    }

    public function test_overlapping_ranges_are_rejected(): void
    {
        $company = $this->createSchedulingCompany();

        $this->expectException(ValidationException::class);

        app(CompanyBusinessHoursService::class)->replaceWeeklyHours($company, [
            ['weekday' => Weekday::Monday->value, 'start_time' => '08:00:00', 'end_time' => '12:00:00'],
            ['weekday' => Weekday::Monday->value, 'start_time' => '11:00:00', 'end_time' => '14:00:00'],
        ]);
    }

    public function test_end_before_start_is_rejected(): void
    {
        $company = $this->createSchedulingCompany();

        $this->expectException(ValidationException::class);

        app(CompanyBusinessHoursService::class)->replaceWeeklyHours($company, [
            ['weekday' => Weekday::Monday->value, 'start_time' => '18:00:00', 'end_time' => '08:00:00'],
        ]);
    }

    public function test_inactive_range_does_not_provide_availability(): void
    {
        $setup = $this->createBookableSetup();
        $company = $setup['company'];
        $professional = $setup['professional'];
        $service = $setup['service'];
        $localStart = $setup['localStart'];

        app(CompanyBusinessHoursService::class)->replaceWeeklyHours($company, [
            [
                'weekday' => $localStart->dayOfWeek,
                'start_time' => '08:00:00',
                'end_time' => '18:00:00',
                'is_active' => false,
            ],
        ]);

        $result = app(AvailabilityService::class)->assertAvailable(
            $company,
            $professional,
            $service,
            $localStart,
            60,
            0,
            0,
        );

        $this->assertFalse($result->available);
    }
}
