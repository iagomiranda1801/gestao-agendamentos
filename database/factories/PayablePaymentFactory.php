<?php

namespace Database\Factories;

use App\Enums\PayablePaymentStatus;
use App\Enums\PaymentMethod;
use App\Models\Company;
use App\Models\FinancialAccount;
use App\Models\Payable;
use App\Models\PayableInstallment;
use App\Models\PayablePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayablePayment>
 */
class PayablePaymentFactory extends Factory
{
    protected $model = PayablePayment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $principal = number_format(fake()->randomFloat(2, 50, 500), 2, '.', '');

        return [
            'company_id' => Company::factory(),
            'payable_id' => Payable::factory(),
            'payable_installment_id' => PayableInstallment::factory(),
            'financial_account_id' => FinancialAccount::factory(),
            'method' => PaymentMethod::Pix,
            'status' => PayablePaymentStatus::Confirmed,
            'settled_principal_amount' => $principal,
            'interest_amount' => '0.00',
            'penalty_amount' => '0.00',
            'fee_amount' => '0.00',
            'discount_amount' => '0.00',
            'cash_outflow_amount' => $principal,
            'paid_at' => now(),
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayablePaymentStatus::Confirmed,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => PayablePaymentStatus::Cancelled,
        ]);
    }
}
