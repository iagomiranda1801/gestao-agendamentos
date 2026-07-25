<?php

namespace Tests\Concerns;

use App\Enums\CompanyRole;
use App\Enums\Weekday;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Services\Scheduling\CompanyBusinessHoursService;
use App\Services\Scheduling\CompanySchedulingSettingService;
use App\Services\Scheduling\ProfessionalWorkingHoursService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Database\Factories\ProfessionalServiceFactory;

trait CreatesSchedulingFixtures
{
    protected function createSchedulingCompany(array $attributes = []): Company
    {
        return Company::factory()->create(array_merge([
            'timezone' => 'America/Sao_Paulo',
            'is_active' => true,
        ], $attributes));
    }

    protected function seedStandardBusinessHours(Company $company): void
    {
        app(CompanyBusinessHoursService::class)->replaceWeeklyHours($company, [
            ['weekday' => Weekday::Monday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Tuesday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Wednesday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Thursday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Friday->value, 'start_time' => '08:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Saturday->value, 'start_time' => '08:00:00', 'end_time' => '12:00:00'],
        ]);
    }

    protected function seedStandardWorkingHours(Company $company, Professional $professional): void
    {
        app(ProfessionalWorkingHoursService::class)->replaceWeekly($company, $professional, [
            ['weekday' => Weekday::Monday->value, 'start_time' => '09:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Tuesday->value, 'start_time' => '09:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Wednesday->value, 'start_time' => '09:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Thursday->value, 'start_time' => '09:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Friday->value, 'start_time' => '09:00:00', 'end_time' => '18:00:00'],
            ['weekday' => Weekday::Saturday->value, 'start_time' => '09:00:00', 'end_time' => '12:00:00'],
        ]);
    }

    protected function seedStandardScheduling(Company $company, Professional $professional): void
    {
        app(CompanySchedulingSettingService::class)->getOrCreate($company);
        $this->seedStandardBusinessHours($company);
        $this->seedStandardWorkingHours($company, $professional);
    }

    /**
     * @return array{
     *     company: Company,
     *     admin: User,
     *     client: Client,
     *     professional: Professional,
     *     service: Service,
     *     localStart: CarbonImmutable
     * }
     */
    protected function createBookableSetup(?Company $company = null): array
    {
        $company ??= $this->createSchedulingCompany();
        $admin = $this->createCompanyUser($company, [], CompanyRole::CompanyAdmin);
        $client = Client::factory()->forCompany($company)->active()->create();
        $professional = Professional::factory()->forCompany($company)->bookable()->active()->create();
        $service = Service::factory()->forCompany($company)->bookable()->active()->create([
            'duration_minutes' => 60,
            'buffer_before_minutes' => 0,
            'buffer_after_minutes' => 0,
        ]);

        ProfessionalServiceFactory::new()
            ->forCompany($company)
            ->create([
                'professional_id' => $professional->getKey(),
                'service_id' => $service->getKey(),
                'is_active' => true,
            ]);

        $this->seedStandardScheduling($company, $professional);
        $localStart = $this->nextWeekdayAt($company, 10, 0);

        return compact('company', 'admin', 'client', 'professional', 'service', 'localStart');
    }

    protected function nextWeekdayAt(Company $company, int $hour, int $minute): CarbonImmutable
    {
        $date = CompanyDateTime::nowLocal($company)->addDay()->startOfDay()->setTime($hour, $minute);

        while (in_array($date->dayOfWeek, [Weekday::Sunday->value, Weekday::Saturday->value], true)) {
            $date = $date->addDay();
        }

        if ($date->lte(CompanyDateTime::nowLocal($company))) {
            $date = $date->addWeek();
        }

        return $date;
    }
}
