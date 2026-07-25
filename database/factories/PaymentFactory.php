<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Attendance;
use App\Models\Company;
use App\Models\Payment;
use App\Models\Receivable;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = number_format(fake()->randomFloat(2, 20, 500), 2, '.', '');
        $feeAmount = '0.00';

        return [
            'company_id' => Company::factory(),
            'receivable_id' => Receivable::factory(),
            'attendance_id' => fn (array $attributes) => Receivable::query()
                ->find($attributes['receivable_id'])?->attendance_id
                ?? Attendance::factory()->create()->getKey(),
            'amount' => $amount,
            'fee_amount' => $feeAmount,
            'net_amount' => bcsub($amount, $feeAmount, 2),
            'method' => PaymentMethod::Pix,
            'status' => PaymentStatus::Confirmed,
            'paid_at' => now(),
            'registered_by' => User::factory(),
        ];
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->getKey(),
            'receivable_id' => Receivable::factory()->forCompany($company),
        ]);
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => PaymentMethod::Cash,
        ]);
    }

    public function pix(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => PaymentMethod::Pix,
        ]);
    }

    public function creditCard(): static
    {
        return $this->state(fn (array $attributes) => [
            'method' => PaymentMethod::CreditCard,
        ]);
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Confirmed,
            'cancelled_by' => null,
            'cancelled_at' => null,
            'cancellation_reason' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Cancelled,
            'cancelled_by' => User::factory(),
            'cancelled_at' => now(),
            'cancellation_reason' => fake()->sentence(),
        ]);
    }

    public function refunded(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PaymentStatus::Refunded,
            'cancelled_by' => User::factory(),
            'cancelled_at' => now(),
            'cancellation_reason' => fake()->sentence(),
        ]);
    }
}
