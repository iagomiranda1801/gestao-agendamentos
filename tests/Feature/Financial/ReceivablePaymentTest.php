<?php

namespace Tests\Feature\Financial;

use App\DataTransferObjects\Financial\PaymentData;
use App\Enums\CommissionType;
use App\Enums\CompanyRole;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReceivableStatus;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Payment;
use App\Models\User;
use App\Services\Financial\AttendanceFinancialCalculator;
use App\Services\Financial\CompanyFinancialSettingService;
use App\Services\Financial\ReceivableService;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesSchedulingFixtures;
use Tests\Support\CreatesFinanceFixtures;
use Tests\TestCase;

class ReceivablePaymentTest extends TestCase
{
    use CreatesFinanceFixtures;
    use CreatesSchedulingFixtures;

    protected Company $company;

    protected User $user;

    protected FinancialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->createSchedulingCompany();
        $this->user = $this->createCompanyUser($this->company, [], CompanyRole::CompanyAdmin);

        app(CompanyFinancialSettingService::class)->update($this->company, [
            'default_commission_type' => CommissionType::Percentage->value,
            'default_commission_value' => '15',
            'materials_reserve_percentage' => '10',
            'business_reserve_percentage' => '10',
            'allow_partial_payments' => true,
            'default_payment_due_days' => 7,
        ]);

        $this->account = $this->createFinancialAccount($this->company);
    }

    public function test_calculator_uses_bcmath_without_floats(): void
    {
        $calculator = app(AttendanceFinancialCalculator::class);

        $this->assertSame('99.99', $calculator->calculateFinalAmount('100.00', '0.01'));
        $this->assertSame('97.00', $calculator->calculateNetAmount('100.00', '3.00'));
        $this->assertSame('40.00', $calculator->calculateOutstandingAmount('100.00', '60.00'));
    }

    public function test_calculator_distribution_respects_percentages(): void
    {
        $result = app(AttendanceFinancialCalculator::class)->calculateDistribution(
            CommissionType::Percentage,
            '15',
            '100.00',
            '10',
            '10',
        );

        $this->assertSame('100.00', $result->finalAmount);
        $this->assertSame('15.00', $result->commissionAmount);
        $this->assertSame('10.00', $result->materialsReserveAmount);
        $this->assertSame('10.00', $result->businessReserveAmount);
        $this->assertSame('65.00', $result->ownerAllocationAmount);
        $this->assertSame('85.00', $result->operationalResult);
    }

    public function test_creates_receivable_from_attendance(): void
    {
        $attendance = $this->createAttendance('150.00');

        $receivable = app(ReceivableService::class)->createForAttendance(
            $this->company,
            $attendance,
            $this->user,
        );

        $this->assertSame('150.00', (string) $receivable->original_amount);
        $this->assertSame('0.00', (string) $receivable->paid_amount);
        $this->assertSame('150.00', (string) $receivable->outstanding_amount);
        $this->assertSame(ReceivableStatus::Open, $receivable->status);
        $this->assertNotNull($receivable->due_date);
        $this->assertNull($receivable->settled_at);
    }

    public function test_registers_payment_with_net_amount(): void
    {
        $attendance = $this->createAttendance('100.00');
        $receivable = app(ReceivableService::class)->createForAttendance($this->company, $attendance, $this->user);

        $payment = app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData(
                amount: '100.00',
                feeAmount: '3.50',
                method: PaymentMethod::CreditCard,
                paidAt: now(),
                financialAccountId: $this->account->getKey(),
            ),
            $this->user,
        );

        $receivable->refresh();

        $this->assertSame('96.50', (string) $payment->net_amount);
        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame('96.50', (string) $receivable->paid_amount);
        $this->assertSame('3.50', (string) $receivable->outstanding_amount);
        $this->assertSame(ReceivableStatus::Partial, $receivable->status);
    }

    public function test_partial_payments_update_status_and_settled_at(): void
    {
        $attendance = $this->createAttendance('100.00');
        $receivable = app(ReceivableService::class)->createForAttendance($this->company, $attendance, $this->user);
        $service = app(ReceivableService::class);

        $service->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('50.00', '0.00', PaymentMethod::Pix, now(), $this->account->getKey()),
            $this->user,
        );

        $receivable->refresh();
        $this->assertSame(ReceivableStatus::Partial, $receivable->status);
        $this->assertNull($receivable->settled_at);

        $service->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('50.00', '0.00', PaymentMethod::Cash, now(), $this->account->getKey()),
            $this->user,
        );

        $receivable->refresh();
        $this->assertSame('100.00', (string) $receivable->paid_amount);
        $this->assertSame('0.00', (string) $receivable->outstanding_amount);
        $this->assertSame(ReceivableStatus::Paid, $receivable->status);
        $this->assertNotNull($receivable->settled_at);
    }

    public function test_rejects_payment_above_outstanding(): void
    {
        $attendance = $this->createAttendance('100.00');
        $receivable = app(ReceivableService::class)->createForAttendance($this->company, $attendance, $this->user);

        $this->expectException(ValidationException::class);

        app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.01', '0.00', PaymentMethod::Pix, now(), $this->account->getKey()),
            $this->user,
        );
    }

    public function test_rejects_partial_payment_when_not_allowed(): void
    {
        app(CompanyFinancialSettingService::class)->update($this->company, [
            'allow_partial_payments' => false,
        ]);

        $attendance = $this->createAttendance('100.00');
        $receivable = app(ReceivableService::class)->createForAttendance($this->company, $attendance, $this->user);

        $this->expectException(ValidationException::class);

        app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('50.00', '0.00', PaymentMethod::Pix, now(), $this->account->getKey()),
            $this->user,
        );
    }

    public function test_cancels_payment_without_deleting_record(): void
    {
        $attendance = $this->createAttendance('100.00');
        $receivable = app(ReceivableService::class)->createForAttendance($this->company, $attendance, $this->user);

        $payment = app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('100.00', '0.00', PaymentMethod::Pix, now(), $this->account->getKey()),
            $this->user,
        );

        app(ReceivableService::class)->cancelPayment($this->company, $payment, $this->user, 'Erro de lançamento');

        $payment->refresh();
        $receivable->refresh();

        $this->assertSame(PaymentStatus::Cancelled, $payment->status);
        $this->assertNotNull($payment->cancelled_at);
        $this->assertDatabaseHas('payments', ['id' => $payment->getKey()]);
        $this->assertSame('0.00', (string) $receivable->paid_amount);
        $this->assertSame('100.00', (string) $receivable->outstanding_amount);
        $this->assertSame(ReceivableStatus::Open, $receivable->status);
        $this->assertNull($receivable->settled_at);
    }

    public function test_recalculates_attendance_payment_fees(): void
    {
        $attendance = $this->createAttendance('100.00');
        $receivable = app(ReceivableService::class)->createForAttendance($this->company, $attendance, $this->user);

        app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('60.00', '2.00', PaymentMethod::CreditCard, now(), $this->account->getKey()),
            $this->user,
        );

        app(ReceivableService::class)->registerPayment(
            $this->company,
            $receivable,
            new PaymentData('40.00', '1.00', PaymentMethod::DebitCard, now(), $this->account->getKey()),
            $this->user,
        );

        $fees = app(AttendanceFinancialCalculator::class)->sumConfirmedPaymentFees(
            Payment::query()->where('attendance_id', $attendance->getKey())->get(),
        );

        $this->assertSame('3.00', $fees);
    }

    protected function createAttendance(string $finalAmount): Attendance
    {
        $setup = $this->createBookableSetup($this->company);

        $appointment = Appointment::factory()
            ->forCompany($this->company)
            ->create([
                'client_id' => $setup['client']->getKey(),
                'professional_id' => $setup['professional']->getKey(),
                'service_id' => $setup['service']->getKey(),
            ]);

        $attendance = Attendance::factory()
            ->forCompany($this->company)
            ->forAppointment($appointment)
            ->create([
                'gross_amount' => $finalAmount,
                'discount_amount' => '0.00',
                'final_amount' => $finalAmount,
                'client_name_snapshot' => $setup['client']->name,
                'professional_name_snapshot' => $setup['professional']->name,
            ]);

        return $attendance->refresh();
    }
}
