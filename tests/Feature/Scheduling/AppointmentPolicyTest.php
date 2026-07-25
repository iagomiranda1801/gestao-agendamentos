<?php

namespace Tests\Feature\Scheduling;

use App\Enums\CompanyRole;
use App\Models\Appointment;
use App\Models\Professional;
use App\Policies\AppointmentPolicy;
use App\Services\Scheduling\AppointmentService;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class AppointmentPolicyTest extends TestCase
{
    use CreatesSchedulingFixtures;

    public function test_company_admin_can_view_all_appointments(): void
    {
        $setup = $this->createBookableSetup();
        $admin = $setup['admin'];

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $admin,
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->authenticateForAppTenant($admin, $setup['company']);

        $this->assertTrue($admin->can('view', $appointment));
        $this->assertTrue($admin->can('create', Appointment::class));
    }

    public function test_employee_sees_only_own_appointments(): void
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

        $own = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $linkedProfessional,
            $setup['service'],
            $setup['localStart'],
        );

        $other = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart']->addHours(2),
        );

        $this->authenticateForAppTenant($employee, $setup['company']);

        $this->assertTrue($employee->can('view', $own));
        $this->assertFalse($employee->can('view', $other));
    }

    public function test_employee_without_professional_cannot_access_calendar(): void
    {
        $setup = $this->createBookableSetup();
        $employee = $this->createCompanyUser($setup['company'], [], CompanyRole::Employee);

        $this->authenticateForAppTenant($employee, $setup['company']);

        $this->assertFalse((new AppointmentPolicy)->viewAny($employee));
    }

    public function test_employee_cannot_create_appointment(): void
    {
        $setup = $this->createBookableSetup();
        $employee = $this->createCompanyUser($setup['company'], [], CompanyRole::Employee);

        Professional::factory()
            ->forCompany($setup['company'])
            ->linkedToUser($employee)
            ->active()
            ->create();

        $this->authenticateForAppTenant($employee, $setup['company']);

        $this->assertFalse($employee->can('create', Appointment::class));
    }

    public function test_user_from_other_company_cannot_view_appointment(): void
    {
        $setup = $this->createBookableSetup();
        $otherCompany = $this->createSchedulingCompany();
        $otherUser = $this->createCompanyUser($otherCompany);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $setup['company'],
            $setup['admin'],
            $setup['client'],
            $setup['professional'],
            $setup['service'],
            $setup['localStart'],
        );

        $this->authenticateForAppTenant($otherUser, $otherCompany);

        $this->assertFalse($otherUser->can('view', $appointment));
    }
}
