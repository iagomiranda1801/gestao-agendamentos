<?php

namespace Tests\Feature\Filament;

use App\Enums\AppointmentStatus;
use App\Enums\CommissionType;
use App\Enums\CompanyRole;
use App\Filament\App\Resources\Appointments\Pages\ViewAppointment;
use App\Filament\App\Resources\Attendances\Pages\ListAttendances;
use App\Filament\App\Resources\Payables\Pages\ListPayables;
use App\Filament\App\Resources\Receivables\Pages\ListReceivables;
use App\Enums\PaymentMethod;
use App\Enums\PayableStatus;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\Professional;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Financial\CompanyFinancialSettingService;
use App\Services\Financial\PayableService;
use App\Services\Financial\ReceivableService;
use App\Services\Scheduling\AppointmentService;
use Livewire\Livewire;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\TestCase;

class FinancialResourceAuthorizationTest extends TestCase
{
    use CreatesSchedulingFixtures;

    protected Company $company;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createSchedulingCompany();
        $this->admin = $this->createCompanyUser($this->company, [], CompanyRole::CompanyAdmin);

        app(CompanyFinancialSettingService::class)->update($this->company, [
            'default_commission_type' => CommissionType::Percentage->value,
            'default_commission_value' => '15',
            'materials_reserve_percentage' => '10',
            'business_reserve_percentage' => '10',
            'allow_partial_payments' => true,
            'allow_unpaid_completion' => true,
            'default_payment_due_days' => 7,
        ]);
    }

    public function test_manager_can_list_receivables(): void
    {
        $manager = $this->createCompanyUser($this->company, [], CompanyRole::Manager);
        $receivable = $this->createReceivableFixture();

        $this->authenticateForAppTenant($manager, $this->company);

        Livewire::test(ListReceivables::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$receivable]);
    }

    public function test_employee_cannot_access_receivable_resource(): void
    {
        $employee = $this->createEmployeeWithProfessional();
        $this->createReceivableFixture();

        $this->authenticateForAppTenant($employee, $this->company);

        Livewire::test(ListReceivables::class)->assertForbidden();
    }

    public function test_manager_can_pay_open_payable_from_table(): void
    {
        $manager = $this->createCompanyUser($this->company, [], CompanyRole::Manager);
        $category = ExpenseCategory::factory()->forCompany($this->company)->create();
        $account = FinancialAccount::factory()
            ->forCompany($this->company)
            ->allowNegativeBalance()
            ->create();

        $payableService = app(PayableService::class);
        $payable = $payableService->createDraft($this->company, $category, [
            'description' => 'Comissão profissional',
            'total_amount' => '15.00',
            'competence_date' => now()->toDateString(),
        ], $this->admin);
        $installments = $payableService->createInstallments($this->company, $payable, [[
            'due_date' => now()->toDateString(),
            'amount' => '15.00',
        ]]);
        $payable = $payableService->launch($this->company, $payable->refresh());

        $this->authenticateForAppTenant($manager, $this->company);

        Livewire::test(ListPayables::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$payable])
            ->assertTableActionVisible('registerPayment', $payable)
            ->callTableAction('registerPayment', $payable, [
                'payable_installment_id' => $installments->first()->getKey(),
                'settled_principal_amount' => '15.00',
                'interest_amount' => '0.00',
                'penalty_amount' => '0.00',
                'fee_amount' => '0.00',
                'discount_amount' => '0.00',
                'method' => PaymentMethod::Pix->value,
                'financial_account_id' => $account->getKey(),
                'paid_at' => now()->toDateTimeString(),
            ]);

        $this->assertSame(PayableStatus::Paid, $payable->fresh()->status);
        $this->assertSame('-15.00', $account->fresh()->getCurrentBalance());
    }

    public function test_employee_can_list_own_attendances_only(): void
    {
        $employee = $this->createEmployeeWithProfessional();
        $ownAttendance = $this->createAttendanceForProfessional($employee);
        $otherAttendance = $this->createAttendanceForSetupProfessional();

        $this->authenticateForAppTenant($employee, $this->company);

        Livewire::test(ListAttendances::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$ownAttendance])
            ->assertCanNotSeeTableRecords([$otherAttendance]);
    }

    public function test_complete_action_is_visible_for_confirmed_appointment_without_attendance(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $this->authenticateForAppTenant($this->admin, $this->company);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertSuccessful()
            ->assertActionVisible('complete');
    }

    public function test_complete_action_is_hidden_when_attendance_already_exists(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);
        Attendance::factory()
            ->forCompany($this->company)
            ->forAppointment($appointment)
            ->create();

        $this->authenticateForAppTenant($this->admin, $this->company);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertSuccessful()
            ->assertActionHidden('complete');
    }

    public function test_complete_action_is_visible_for_employee_on_own_appointment(): void
    {
        $employee = $this->createEmployeeWithProfessional();
        $professional = Professional::query()
            ->where('company_id', $this->company->getKey())
            ->where('user_id', $employee->getKey())
            ->firstOrFail();

        $setup = $this->createBookableSetup($this->company);
        $professional->services()->syncWithoutDetaching([
            $setup['service']->getKey() => [
                'company_id' => $this->company->getKey(),
                'is_active' => true,
            ],
        ]);
        $this->seedStandardWorkingHours($this->company, $professional);

        $appointment = app(AppointmentService::class)->createInternalAppointment(
            $this->company,
            $this->admin,
            $setup['client'],
            $professional,
            $setup['service'],
            $setup['localStart'],
        );
        $appointment->update(['status' => AppointmentStatus::Confirmed]);

        $this->authenticateForAppTenant($employee, $this->company);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertSuccessful()
            ->assertActionVisible('complete');
    }

    public function test_complete_action_is_hidden_for_employee_on_other_professional_appointment(): void
    {
        $employee = $this->createEmployeeWithProfessional();
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $this->authenticateForAppTenant($employee, $this->company);

        Livewire::test(ViewAppointment::class, ['record' => $appointment->getKey()])
            ->assertForbidden();
    }

    protected function createEmployeeWithProfessional(): User
    {
        $employee = $this->createCompanyUser($this->company, [], CompanyRole::Employee);

        Professional::factory()
            ->forCompany($this->company)
            ->linkedToUser($employee)
            ->bookable()
            ->active()
            ->create();

        return $employee;
    }

    protected function createCompletableAppointment(AppointmentStatus $status): Appointment
    {
        $setup = $this->createBookableSetup($this->company);

        return Appointment::factory()
            ->forCompany($this->company)
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'status' => $status,
                'price_snapshot' => '100.00',
                'client_name_snapshot' => $setup['client']->name,
            ]);
    }

    protected function createAttendanceForProfessional(User $employee): Attendance
    {
        $professional = Professional::query()
            ->where('company_id', $this->company->getKey())
            ->where('user_id', $employee->getKey())
            ->firstOrFail();

        $setup = $this->createBookableSetup($this->company);
        $professional->services()->syncWithoutDetaching([
            $setup['service']->getKey() => [
                'company_id' => $this->company->getKey(),
                'is_active' => true,
            ],
        ]);

        $appointment = Appointment::factory()
            ->forCompany($this->company)
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $professional->getKey(),
                'service_id' => $setup['service']->getKey(),
                'status' => AppointmentStatus::Completed,
                'client_name_snapshot' => $setup['client']->name,
            ]);

        return Attendance::factory()
            ->forCompany($this->company)
            ->forAppointment($appointment)
            ->create([
                'client_name_snapshot' => $setup['client']->name,
                'professional_name_snapshot' => $professional->name,
            ]);
    }

    protected function createAttendanceForSetupProfessional(): Attendance
    {
        $setup = $this->createBookableSetup($this->company);

        $appointment = Appointment::factory()
            ->forCompany($this->company)
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'status' => AppointmentStatus::Completed,
                'client_name_snapshot' => $setup['client']->name,
            ]);

        return Attendance::factory()
            ->forCompany($this->company)
            ->forAppointment($appointment)
            ->create([
                'client_name_snapshot' => $setup['client']->name,
                'professional_name_snapshot' => $setup['professional']->name,
            ]);
    }

    protected function createReceivableFixture(): Receivable
    {
        $attendance = $this->createAttendanceForSetupProfessional();

        return app(ReceivableService::class)->createForAttendance(
            $this->company,
            $attendance,
            $this->admin,
        );
    }
}
