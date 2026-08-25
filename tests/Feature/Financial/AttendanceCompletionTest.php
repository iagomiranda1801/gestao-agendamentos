<?php

namespace Tests\Feature\Financial;

use App\DataTransferObjects\Financial\AttendanceCompletionData;
use App\DataTransferObjects\Financial\AttendanceMaterialInput;
use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\AppointmentHistoryType;
use App\Enums\AppointmentStatus;
use App\Enums\AttendanceHistoryType;
use App\Enums\CommissionType;
use App\Enums\CompanyRole;
use App\Enums\PayableOrigin;
use App\Enums\PayableStatus;
use App\Enums\PaymentMethod;
use App\Enums\ReceivableStatus;
use App\Enums\StockDocumentType;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\InventoryBalance;
use App\Models\Payable;
use App\Models\Product;
use App\Models\User;
use App\Services\Financial\AttendanceCompletionService;
use App\Services\Financial\CompanyFinancialSettingService;
use App\Services\Financial\ManagerialDreAggregator;
use App\Services\Financial\PayableService;
use App\Services\Scheduling\AppointmentStatusService;
use App\Support\CompanyDateTime;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\Support\CreatesFinanceFixtures;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class AttendanceCompletionTest extends TestCase
{
    use CreatesFinanceFixtures;
    use CreatesSchedulingFixtures;
    use CreatesStockFixtures;

    protected Company $company;

    protected User $user;

    protected AttendanceCompletionService $service;

    protected FinancialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createSchedulingCompany();
        $this->user = $this->createCompanyUser($this->company, [], CompanyRole::CompanyAdmin);
        $this->service = app(AttendanceCompletionService::class);

        app(CompanyFinancialSettingService::class)->update($this->company, [
            'default_commission_type' => CommissionType::Percentage->value,
            'default_commission_value' => '15',
            'materials_reserve_percentage' => '10',
            'business_reserve_percentage' => '10',
            'allow_partial_payments' => true,
            'allow_unpaid_completion' => true,
            'default_payment_due_days' => 7,
        ]);

        $this->account = $this->createFinancialAccount($this->company);

        $this->authenticateForAppTenant($this->user, $this->company);
    }

    public function test_confirmed_appointment_can_be_completed(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $attendance = $this->complete($appointment);

        $this->assertInstanceOf(Attendance::class, $attendance);
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    public function test_in_progress_appointment_can_be_completed(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);
        app(AppointmentStatusService::class)->start($this->company, $this->user, $appointment);

        $attendance = $this->complete($appointment->fresh());

        $this->assertInstanceOf(Attendance::class, $attendance);
        $this->assertSame(AppointmentStatus::Completed, $appointment->fresh()->status);
    }

    #[DataProvider('nonCompletableStatusProvider')]
    public function test_non_completable_statuses_are_rejected(AppointmentStatus $status): void
    {
        $appointment = $this->createCompletableAppointment($status);

        $this->expectException(AuthorizationException::class);

        $this->complete($appointment);
    }

    /**
     * @return array<string, array{0: AppointmentStatus}>
     */
    public static function nonCompletableStatusProvider(): array
    {
        return [
            'pending' => [AppointmentStatus::Pending],
            'cancelled' => [AppointmentStatus::Cancelled],
            'no_show' => [AppointmentStatus::NoShow],
            'completed' => [AppointmentStatus::Completed],
        ];
    }

    public function test_creates_attendance_with_unique_appointment_id(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $attendance = $this->complete($appointment);

        $this->assertDatabaseHas('attendances', [
            'id' => $attendance->getKey(),
            'appointment_id' => $appointment->getKey(),
            'company_id' => $this->company->getKey(),
        ]);
        $this->assertTrue($appointment->fresh()->attendance->is($attendance));

        $this->expectException(AuthorizationException::class);

        $this->complete($appointment->fresh());
    }

    public function test_deducts_stock_for_tracked_products(): void
    {
        $product = $this->createTrackedProduct($this->company, ['reference_unit_cost' => '2.000000']);

        $this->postOpeningBalance($this->company, $this->user, [[
            'product_id' => $product->getKey(),
            'quantity' => '20',
            'unit_cost' => '2.000000',
        ]]);

        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $this->complete($appointment, materials: [
            new AttendanceMaterialInput(
                productId: $product->getKey(),
                plannedQuantity: '3',
                quantity: '3',
            ),
        ]);

        $balance = InventoryBalance::query()
            ->where('product_id', $product->getKey())
            ->first();

        $this->assertSame('17.0000', (string) $balance->quantity_on_hand);
        $this->assertDatabaseHas('stock_documents', [
            'company_id' => $this->company->getKey(),
            'type' => StockDocumentType::ServiceConsumption->value,
        ]);
    }

    public function test_insufficient_stock_blocks_completion(): void
    {
        $product = $this->createTrackedProduct($this->company);

        $this->postOpeningBalance($this->company, $this->user, [[
            'product_id' => $product->getKey(),
            'quantity' => '2',
            'unit_cost' => '2.000000',
        ]]);

        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $this->expectException(ValidationException::class);

        try {
            $this->complete($appointment, materials: [
                new AttendanceMaterialInput(
                    productId: $product->getKey(),
                    plannedQuantity: '5',
                    quantity: '5',
                ),
            ]);
        } finally {
            $this->assertDatabaseCount('attendances', 0);
            $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);

            $balance = InventoryBalance::query()
                ->where('product_id', $product->getKey())
                ->first();

            $this->assertSame('2.0000', (string) $balance->quantity_on_hand);
        }
    }

    public function test_allows_unpaid_completion_when_setting_enabled(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $attendance = $this->complete($appointment, payments: []);

        $receivable = $attendance->receivable;

        $this->assertNotNull($receivable);
        $this->assertSame(ReceivableStatus::Open, $receivable->status);
        $this->assertSame('100.00', (string) $receivable->outstanding_amount);
    }

    public function test_allows_partial_payment_completion(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $attendance = $this->complete($appointment, payments: [
            new PaymentData('50.00', '0.00', PaymentMethod::Pix, now(), $this->account->getKey()),
        ]);

        $receivable = $attendance->receivable->fresh();

        $this->assertSame(ReceivableStatus::Partial, $receivable->status);
        $this->assertSame('50.00', (string) $receivable->paid_amount);
        $this->assertSame('50.00', (string) $receivable->outstanding_amount);
    }

    public function test_registers_full_payment_on_completion(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $attendance = $this->complete($appointment, payments: [
            new PaymentData('100.00', '0.00', PaymentMethod::CreditCard, now(), $this->account->getKey()),
        ]);

        $receivable = $attendance->receivable->fresh();

        $this->assertSame(ReceivableStatus::Paid, $receivable->status);
        $this->assertSame('100.00', (string) $receivable->paid_amount);
        $this->assertSame('0.00', (string) $receivable->outstanding_amount);
        $this->assertSame('0.00', (string) $attendance->fresh()->payment_fee_amount);
    }

    public function test_recalculates_operational_result_with_payment_fees(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $attendance = $this->complete($appointment, payments: [
            new PaymentData('102.50', '2.50', PaymentMethod::CreditCard, now(), $this->account->getKey()),
        ]);

        $this->assertSame('2.50', (string) $attendance->fresh()->payment_fee_amount);
        $this->assertSame('82.50', (string) $attendance->fresh()->operational_result);
    }

    public function test_rejects_unpaid_completion_when_not_allowed(): void
    {
        app(CompanyFinancialSettingService::class)->update($this->company, [
            'allow_unpaid_completion' => false,
        ]);

        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $this->expectException(ValidationException::class);

        $this->complete($appointment, payments: []);
    }

    public function test_financial_distribution_for_one_hundred_reais(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed, '100.00');

        $attendance = $this->complete($appointment);

        $this->assertSame('100.00', (string) $attendance->gross_amount);
        $this->assertSame('100.00', (string) $attendance->final_amount);
        $this->assertSame('15.00', (string) $attendance->commission_amount);
        $this->assertSame('10.00', (string) $attendance->materials_reserve_amount);
        $this->assertSame('10.00', (string) $attendance->business_reserve_amount);
        $this->assertSame('65.00', (string) $attendance->owner_allocation_amount);
        $this->assertSame('85.00', (string) $attendance->operational_result);
    }

    public function test_completion_creates_open_payable_for_professional_commission(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed, '100.00');

        $attendance = $this->complete($appointment);
        $payable = $attendance->commissionPayable()->with(['installments', 'expenseCategory'])->first();

        $this->assertNotNull($payable);
        $this->assertSame(PayableOrigin::ProfessionalCommission, $payable->origin);
        $this->assertSame(PayableStatus::Open, $payable->status);
        $this->assertSame('15.00', (string) $payable->total_amount);
        $this->assertSame($attendance->professional_id, $payable->professional_id);
        $this->assertSame("attendance:{$attendance->getKey()}:professional-commission", $payable->reference_key);
        $this->assertFalse($payable->expenseCategory->affects_managerial_result);
        $this->assertTrue($payable->expenseCategory->is_system);
        $this->assertSame('Comissões profissionais', $payable->expenseCategory->name);
        $this->assertSame('15.00', (string) $payable->installments->first()->outstanding_amount);
    }

    public function test_completion_does_not_create_payable_when_commission_is_zero(): void
    {
        app(CompanyFinancialSettingService::class)->update($this->company, [
            'default_commission_type' => CommissionType::None->value,
            'default_commission_value' => '0',
        ]);

        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed, '100.00');

        $attendance = $this->complete($appointment);

        $this->assertSame('0.00', (string) $attendance->commission_amount);
        $this->assertNull($attendance->commissionPayable);
        $this->assertDatabaseCount('payables', 0);
    }

    public function test_commission_payable_generation_is_idempotent(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed, '100.00');

        $attendance = $this->complete($appointment);
        $firstPayable = $attendance->commissionPayable()->firstOrFail();

        $secondPayable = app(PayableService::class)->createFromAttendanceCommission(
            $this->company,
            $attendance->refresh(),
            $this->user,
        );

        $this->assertNotNull($secondPayable);
        $this->assertTrue($firstPayable->is($secondPayable));
        $this->assertSame(1, Payable::query()->count());
    }

    public function test_commission_payable_does_not_double_count_in_managerial_dre(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed, '100.00');

        $this->complete($appointment);

        $timezone = CompanyDateTime::timezone($this->company);
        $summary = app(ManagerialDreAggregator::class)->aggregate(
            $this->company,
            CarbonImmutable::parse(now($timezone)->toDateString().' 00:00:00', $timezone),
            CarbonImmutable::parse(now($timezone)->toDateString().' 23:59:59', $timezone),
        );

        $this->assertSame('15.00', $summary->commissions);
        $this->assertSame('0.00', $summary->operationalExpenses);
        $this->assertSame('85.00', $summary->operationalResult);
    }

    public function test_records_completion_histories(): void
    {
        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $attendance = $this->complete($appointment);

        $this->assertDatabaseHas('appointment_histories', [
            'appointment_id' => $appointment->getKey(),
            'type' => AppointmentHistoryType::Completed->value,
            'new_status' => AppointmentStatus::Completed->value,
        ]);

        $this->assertDatabaseHas('attendance_histories', [
            'attendance_id' => $attendance->getKey(),
            'type' => AttendanceHistoryType::Completed->value,
        ]);
    }

    public function test_rejects_asset_products_in_materials(): void
    {
        $product = Product::factory()
            ->forCompany($this->company)
            ->asset()
            ->create(['tracks_stock' => false]);

        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $this->expectException(ValidationException::class);

        $this->complete($appointment, materials: [
            new AttendanceMaterialInput(
                productId: $product->getKey(),
                plannedQuantity: '1',
                quantity: '1',
            ),
        ]);
    }

    public function test_creates_attendance_material_with_cost_from_stock_movement(): void
    {
        $product = $this->createTrackedProduct($this->company, ['reference_unit_cost' => '1.000000']);

        $this->postOpeningBalance($this->company, $this->user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '4.000000',
        ]]);

        $appointment = $this->createCompletableAppointment(AppointmentStatus::Confirmed);

        $attendance = $this->complete($appointment, materials: [
            new AttendanceMaterialInput(
                productId: $product->getKey(),
                plannedQuantity: '2',
                quantity: '2',
            ),
        ]);

        $material = $attendance->materials->first();

        $this->assertSame('4.000000', (string) $material->unit_cost_snapshot);
        $this->assertSame('8.000000', (string) $material->total_cost);
        $this->assertSame('8.00', (string) $attendance->actual_material_cost);
        $this->assertSame('77.00', (string) $attendance->operational_result);
    }

    protected function createCompletableAppointment(
        AppointmentStatus $status,
        string $price = '100.00',
    ): Appointment {
        $setup = $this->createBookableSetup($this->company);

        return Appointment::factory()
            ->forCompany($this->company)
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
                'status' => $status,
                'price_snapshot' => $price,
                'client_name_snapshot' => $setup['client']->name,
            ]);
    }

    public function test_open_service_appointment_uses_actual_service_when_completed(): void
    {
        $setup = $this->createBookableSetup($this->company);
        $appointment = Appointment::factory()
            ->forCompany($this->company)
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => null,
                'service_selection_mode' => 'to_be_defined',
                'service_name_snapshot' => 'A definir no atendimento',
                'price_snapshot' => null,
                'status' => AppointmentStatus::Confirmed,
                'client_name_snapshot' => $setup['client']->name,
            ]);

        $attendance = $this->service->complete(
            $this->company,
            $this->user,
            $appointment,
            new AttendanceCompletionData(
                discountAmount: '0.00',
                materials: [],
                payments: [],
                grossAmount: '150.00',
                actualServiceId: $setup['service']->getKey(),
            ),
        );

        $this->assertSame($setup['service']->getKey(), $attendance->service_id);
        $this->assertSame($setup['service']->name, $attendance->service_name_snapshot);
        $this->assertSame('150.00', (string) $attendance->gross_amount);
    }

    /**
     * @param  list<AttendanceMaterialInput>  $materials
     * @param  list<PaymentData>  $payments
     */
    protected function complete(
        Appointment $appointment,
        array $materials = [],
        array $payments = [],
        ?string $discountAmount = '0.00',
    ): Attendance {
        return $this->service->complete(
            $this->company,
            $this->user,
            $appointment,
            new AttendanceCompletionData(
                discountAmount: $discountAmount,
                materials: $materials,
                payments: $payments,
            ),
        );
    }
}
