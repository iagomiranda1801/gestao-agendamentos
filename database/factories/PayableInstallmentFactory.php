<?php

namespace Database\Factories;

use App\Enums\PayableStatus;
use App\Models\Company;
use App\Models\Payable;
use App\Models\PayableInstallment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PayableInstallment>
 */
class PayableInstallmentFactory extends Factory
{
    protected $model = PayableInstallment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = number_format(fake()->randomFloat(2, 50, 500), 2, '.', '');

        return [
            'company_id' => Company::factory(),
            'payable_id' => Payable::factory(),
            'installment_number' => 1,
            'due_date' => now()->addDays(30)->toDateString(),
            'original_amount' => $amount,
            'settled_principal_amount' => '0.00',
            'outstanding_amount' => $amount,
            'status' => PayableStatus::Open,
        ];
    }
}
