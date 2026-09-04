<?php

namespace Database\Factories;

use App\Enums\BillingInterval;
use App\Enums\CompanyModule;
use App\Enums\PlatformInvoiceStatus;
use App\Models\Company;
use App\Models\PlatformInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformInvoice>
 */
class PlatformInvoiceFactory extends Factory
{
    protected $model = PlatformInvoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = now();

        return [
            'company_id' => Company::factory(),
            'number' => 'AQ-'.now()->year.'-'.fake()->unique()->numerify('####'),
            'status' => PlatformInvoiceStatus::Open,
            'billing_interval' => BillingInterval::Monthly,
            'amount_cents' => 3900,
            'items' => [[
                'module' => CompanyModule::Sales->value,
                'label' => CompanyModule::Sales->label(),
                'price_cents' => 3900,
            ]],
            'period_start' => $periodStart,
            'period_end' => $periodStart->copy()->addMonth(),
            'due_at' => $periodStart->copy()->addDays(3),
        ];
    }
}
