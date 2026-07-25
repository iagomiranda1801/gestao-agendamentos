<?php

namespace Database\Factories;

use App\Enums\CommissionType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\ReceivableStatus;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\Client;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Professional;
use App\Models\Receivable;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Attendance>
 */
class AttendanceFactory extends Factory
{
    protected $model = Attendance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grossAmount = fake()->randomFloat(2, 50, 300);
        $discountAmount = '0.00';
        $finalAmount = bcsub((string) $grossAmount, $discountAmount, 2);

        return [
            'company_id' => Company::factory(),
            'appointment_id' => Appointment::factory(),
            'client_id' => Client::factory(),
            'professional_id' => Professional::factory(),
            'service_id' => Service::factory(),
            'service_name_snapshot' => fake()->words(3, true),
            'client_name_snapshot' => fake()->name(),
            'professional_name_snapshot' => fake()->name(),
            'gross_amount' => $grossAmount,
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'commission_type_snapshot' => CommissionType::Percentage,
            'commission_value_snapshot' => '15.0000',
            'commission_amount' => bcmul($finalAmount, '0.15', 2),
            'materials_reserve_percentage_snapshot' => '10.0000',
            'materials_reserve_amount' => bcmul($finalAmount, '0.10', 2),
            'business_reserve_percentage_snapshot' => '10.0000',
            'business_reserve_amount' => bcmul($finalAmount, '0.10', 2),
            'owner_allocation_percentage_snapshot' => '65.0000',
            'owner_allocation_amount' => bcmul($finalAmount, '0.65', 2),
            'actual_material_cost' => '0.00',
            'payment_fee_amount' => '0.00',
            'operational_result' => '0.00',
            'notes' => fake()->optional()->sentence(),
            'internal_notes' => fake()->optional()->sentence(),
            'completed_by' => User::factory(),
            'completed_at' => now(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
        ]);
    }

    public function forAppointment(Appointment $appointment): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $appointment->company_id,
            'appointment_id' => $appointment->getKey(),
            'client_id' => $appointment->client_id,
            'professional_id' => $appointment->professional_id,
            'service_id' => $appointment->service_id,
            'service_name_snapshot' => $appointment->service_name_snapshot,
        ]);
    }

    public function paid(): static
    {
        return $this->afterCreating(function (Attendance $attendance): void {
            $this->createReceivableWithPaymentState($attendance, 'paid');
        });
    }

    public function partial(): static
    {
        return $this->afterCreating(function (Attendance $attendance): void {
            $this->createReceivableWithPaymentState($attendance, 'partial');
        });
    }

    public function unpaid(): static
    {
        return $this->afterCreating(function (Attendance $attendance): void {
            $this->createReceivableWithPaymentState($attendance, 'unpaid');
        });
    }

    protected function createReceivableWithPaymentState(Attendance $attendance, string $state): void
    {
        $finalAmount = (string) $attendance->final_amount;

        $receivable = Receivable::factory()
            ->forCompany($attendance->company)
            ->create([
                'attendance_id' => $attendance->getKey(),
                'client_id' => $attendance->client_id,
                'original_amount' => $finalAmount,
            ]);

        match ($state) {
            'paid' => $this->seedPaidReceivable($attendance, $receivable, $finalAmount),
            'partial' => $this->seedPartialReceivable($attendance, $receivable, $finalAmount),
            default => $this->seedUnpaidReceivable($receivable, $finalAmount),
        };
    }

    protected function seedPaidReceivable(Attendance $attendance, Receivable $receivable, string $finalAmount): void
    {
        $receivable->update([
            'paid_amount' => $finalAmount,
            'outstanding_amount' => '0.00',
            'status' => ReceivableStatus::Paid,
            'settled_at' => now(),
        ]);

        Payment::factory()
            ->forCompany($attendance->company)
            ->confirmed()
            ->create([
                'receivable_id' => $receivable->getKey(),
                'attendance_id' => $attendance->getKey(),
                'amount' => $finalAmount,
                'fee_amount' => '0.00',
                'net_amount' => $finalAmount,
                'method' => PaymentMethod::Pix,
                'status' => PaymentStatus::Confirmed,
                'registered_by' => $attendance->completed_by,
            ]);
    }

    protected function seedPartialReceivable(Attendance $attendance, Receivable $receivable, string $finalAmount): void
    {
        $paidAmount = bcdiv($finalAmount, '2', 2);
        $outstandingAmount = bcsub($finalAmount, $paidAmount, 2);

        $receivable->update([
            'paid_amount' => $paidAmount,
            'outstanding_amount' => $outstandingAmount,
            'status' => ReceivableStatus::Partial,
        ]);

        Payment::factory()
            ->forCompany($attendance->company)
            ->confirmed()
            ->create([
                'receivable_id' => $receivable->getKey(),
                'attendance_id' => $attendance->getKey(),
                'amount' => $paidAmount,
                'fee_amount' => '0.00',
                'net_amount' => $paidAmount,
                'method' => PaymentMethod::Cash,
                'status' => PaymentStatus::Confirmed,
                'registered_by' => $attendance->completed_by,
            ]);
    }

    protected function seedUnpaidReceivable(Receivable $receivable, string $finalAmount): void
    {
        $receivable->update([
            'paid_amount' => '0.00',
            'outstanding_amount' => $finalAmount,
            'status' => ReceivableStatus::Open,
            'settled_at' => null,
        ]);
    }
}
