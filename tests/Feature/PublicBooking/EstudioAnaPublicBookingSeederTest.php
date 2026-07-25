<?php

namespace Tests\Feature\PublicBooking;

use App\Models\Company;
use App\Models\CompanySchedulingSetting;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EstudioAnaPublicBookingSeeder;
use Database\Seeders\MeasurementUnitSeeder;
use Database\Seeders\TenantFoundationSeeder;
use Tests\TestCase;

class EstudioAnaPublicBookingSeederTest extends TestCase
{
    protected function seedPrerequisites(): Company
    {
        $this->seed(TenantFoundationSeeder::class);
        $this->seed(MeasurementUnitSeeder::class);
        $this->seed(DemoDataSeeder::class);

        return Company::query()->where('slug', 'estudio-ana')->firstOrFail();
    }

    public function test_seeder_enables_public_booking_settings(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaPublicBookingSeeder::class);

        $settings = CompanySchedulingSetting::query()
            ->where('company_id', $company->getKey())
            ->first();

        $this->assertNotNull($settings);
        $this->assertTrue($settings->public_booking_enabled);
        $this->assertFalse($settings->online_auto_confirm);
        $this->assertTrue($settings->allow_public_cancellation);
        $this->assertTrue($settings->allow_public_reschedule);
        $this->assertSame('Agende seu horário', $settings->booking_page_title);
        $this->assertSame('#7c3aed', $settings->booking_primary_color);
        $this->assertSame(120, $settings->minimum_advance_minutes);
        $this->assertSame(720, $settings->cancellation_minimum_advance_minutes);
    }

    public function test_seeder_is_idempotent(): void
    {
        $company = $this->seedPrerequisites();

        $this->seed(EstudioAnaPublicBookingSeeder::class);
        $settings = CompanySchedulingSetting::query()
            ->where('company_id', $company->getKey())
            ->firstOrFail()
            ->toArray();

        $this->seed(EstudioAnaPublicBookingSeeder::class);
        $settingsAfterSecondRun = CompanySchedulingSetting::query()
            ->where('company_id', $company->getKey())
            ->firstOrFail()
            ->toArray();

        $this->assertSame(
            CompanySchedulingSetting::query()->where('company_id', $company->getKey())->count(),
            1,
        );
        $this->assertSame($settings['public_booking_enabled'], $settingsAfterSecondRun['public_booking_enabled']);
        $this->assertSame($settings['booking_page_title'], $settingsAfterSecondRun['booking_page_title']);
        $this->assertSame($settings['booking_primary_color'], $settingsAfterSecondRun['booking_primary_color']);
        $this->assertSame($settings['minimum_advance_minutes'], $settingsAfterSecondRun['minimum_advance_minutes']);
    }

    public function test_seeder_noops_when_company_is_missing(): void
    {
        $this->assertDatabaseMissing('companies', ['slug' => 'estudio-ana']);

        $this->seed(EstudioAnaPublicBookingSeeder::class);

        $this->assertDatabaseMissing('companies', ['slug' => 'estudio-ana']);
        $this->assertSame(0, CompanySchedulingSetting::query()->count());
    }
}
