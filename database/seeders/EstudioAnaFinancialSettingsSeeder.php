<?php

namespace Database\Seeders;

use App\Enums\CommissionType;
use App\Models\Company;
use App\Services\Financial\CompanyFinancialSettingService;
use Illuminate\Database\Seeder;

class EstudioAnaFinancialSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('slug', 'estudio-ana')->first();

        if (! $company) {
            return;
        }

        app(CompanyFinancialSettingService::class)->update($company, [
            'default_commission_type' => CommissionType::Percentage->value,
            'default_commission_value' => '15',
            'materials_reserve_percentage' => '10',
            'business_reserve_percentage' => '10',
            'allow_partial_payments' => true,
            'allow_unpaid_completion' => true,
            'default_payment_due_days' => 0,
        ]);
    }
}
