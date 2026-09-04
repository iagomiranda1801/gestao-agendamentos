<?php

namespace Tests\Feature\Professionals;

use App\Enums\Weekday;
use App\Filament\App\Resources\Professionals\Pages\EditProfessional;
use App\Filament\App\Resources\Professionals\RelationManagers\WorkingHoursRelationManager;
use App\Models\Professional;
use Livewire\Livewire;
use Tests\TestCase;

class WorkingHoursRelationManagerTest extends TestCase
{
    public function test_apply_to_days_creates_weekday_hours_in_bulk(): void
    {
        $company = $this->createCompany();
        $admin = $this->createCompanyUser($company);
        $professional = Professional::factory()->forCompany($company)->bookable()->active()->create();

        $this->authenticateForAppTenant($admin, $company);

        Livewire::test(WorkingHoursRelationManager::class, [
            'ownerRecord' => $professional,
            'pageClass' => EditProfessional::class,
        ])
            ->assertSuccessful()
            ->callTableAction('applyToDays', data: [
                'weekdays' => [
                    Weekday::Monday->value,
                    Weekday::Tuesday->value,
                    Weekday::Wednesday->value,
                    Weekday::Thursday->value,
                    Weekday::Friday->value,
                ],
                'start_time' => '09:00',
                'end_time' => '18:00',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(5, $professional->workingHours()->count());
        $this->assertEqualsCanonicalizing(
            [
                Weekday::Monday->value,
                Weekday::Tuesday->value,
                Weekday::Wednesday->value,
                Weekday::Thursday->value,
                Weekday::Friday->value,
            ],
            $professional->workingHours()->pluck('weekday')->all(),
        );
    }
}
