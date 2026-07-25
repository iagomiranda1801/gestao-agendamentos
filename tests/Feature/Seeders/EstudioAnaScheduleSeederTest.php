<?php

namespace Tests\Feature\Seeders;

use App\Models\Appointment;
use App\Models\Company;
use App\Models\CompanyBusinessHour;
use App\Models\CompanySchedulingSetting;
use App\Models\InventoryBalance;
use App\Models\ProfessionalBreak;
use App\Models\ProfessionalWorkingHour;
use App\Models\StockMovement;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EstudioAnaCatalogSeeder;
use Database\Seeders\EstudioAnaOpeningInventorySeeder;
use Database\Seeders\EstudioAnaScheduleSeeder;
use Database\Seeders\MeasurementUnitSeeder;
use Database\Seeders\TenantFoundationSeeder;
use Tests\TestCase;

class EstudioAnaScheduleSeederTest extends TestCase
{
    protected function seedPrerequisites(): Company
    {
        $this->seed(TenantFoundationSeeder::class);
        $this->seed(MeasurementUnitSeeder::class);
        $this->seed(DemoDataSeeder::class);
        $this->seed(EstudioAnaCatalogSeeder::class);
        $this->seed(EstudioAnaOpeningInventorySeeder::class);

        return Company::query()->where('slug', 'estudio-ana')->firstOrFail();
    }

    public function test_seeder_creates_company_scheduling_configuration(): void
    {
        $company = $this->seedPrerequisites();
        $movementsBefore = StockMovement::query()->count();
        $balancesBefore = InventoryBalance::query()->count();

        $this->seed(EstudioAnaScheduleSeeder::class);

        $this->assertDatabaseHas('company_scheduling_settings', [
            'company_id' => $company->getKey(),
            'slot_interval_minutes' => 15,
        ]);

        $this->assertGreaterThan(0, CompanyBusinessHour::query()->where('company_id', $company->getKey())->count());
        $this->assertGreaterThan(0, ProfessionalWorkingHour::query()->where('company_id', $company->getKey())->count());
        $this->assertGreaterThan(0, ProfessionalBreak::query()->where('company_id', $company->getKey())->count());
        $this->assertSame(3, Appointment::query()->where('company_id', $company->getKey())->count());

        $this->assertSame($movementsBefore, StockMovement::query()->count());
        $this->assertSame($balancesBefore, InventoryBalance::query()->count());
    }

    public function test_seeder_is_idempotent(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaScheduleSeeder::class);
        $settingsCount = CompanySchedulingSetting::query()->where('company_id', $company->getKey())->count();
        $hoursCount = CompanyBusinessHour::query()->where('company_id', $company->getKey())->count();
        $appointmentsCount = Appointment::query()->where('company_id', $company->getKey())->count();

        $this->seed(EstudioAnaScheduleSeeder::class);

        $this->assertSame($settingsCount, CompanySchedulingSetting::query()->where('company_id', $company->getKey())->count());
        $this->assertSame($hoursCount, CompanyBusinessHour::query()->where('company_id', $company->getKey())->count());
        $this->assertSame($appointmentsCount, Appointment::query()->where('company_id', $company->getKey())->count());
    }

    public function test_seeded_appointments_are_in_the_future(): void
    {
        $company = $this->seedPrerequisites();
        $this->seed(EstudioAnaScheduleSeeder::class);

        Appointment::query()
            ->where('company_id', $company->getKey())
            ->each(function (Appointment $appointment): void {
                $this->assertTrue($appointment->start_at->isFuture());
            });
    }
}
