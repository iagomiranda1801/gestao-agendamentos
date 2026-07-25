<?php

namespace App\Services\Financial;

use App\Enums\CommissionType;
use App\Models\Company;
use App\Models\CompanyFinancialSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompanyFinancialSettingService
{
    public function getOrCreate(Company $company): CompanyFinancialSetting
    {
        $setting = CompanyFinancialSetting::query()
            ->where('company_id', $company->getKey())
            ->first();

        if ($setting) {
            return $setting;
        }

        $setting = new CompanyFinancialSetting([
            'default_commission_type' => CommissionType::Percentage,
            'default_commission_value' => '0.0000',
            'materials_reserve_percentage' => '0.0000',
            'business_reserve_percentage' => '0.0000',
            'allow_partial_payments' => true,
            'allow_unpaid_completion' => true,
            'default_payment_due_days' => 0,
        ]);
        $setting->company()->associate($company);
        $setting->save();

        return $setting->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): CompanyFinancialSetting
    {
        return DB::transaction(function () use ($company, $data): CompanyFinancialSetting {
            $setting = $this->getOrCreate($company);
            $payload = $this->preparePayload($data);

            $this->validatePayload($payload);

            $setting->fill($payload);
            $setting->save();

            return $setting->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function preparePayload(array $data): array
    {
        $payload = [];

        if (array_key_exists('default_commission_type', $data)) {
            $payload['default_commission_type'] = $data['default_commission_type'] instanceof CommissionType
                ? $data['default_commission_type']
                : CommissionType::from((string) $data['default_commission_type']);
        }

        foreach ([
            'default_commission_value',
            'materials_reserve_percentage',
            'business_reserve_percentage',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = (string) $data[$field];
            }
        }

        foreach (['allow_partial_payments', 'allow_unpaid_completion'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = (bool) $data[$field];
            }
        }

        if (array_key_exists('default_payment_due_days', $data)) {
            $payload['default_payment_due_days'] = (int) $data['default_payment_due_days'];
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function validatePayload(array $payload): void
    {
        $commissionType = $payload['default_commission_type'] ?? CommissionType::Percentage;
        $commissionValue = (string) ($payload['default_commission_value'] ?? '0');
        $materialsReserve = (string) ($payload['materials_reserve_percentage'] ?? '0');
        $businessReserve = (string) ($payload['business_reserve_percentage'] ?? '0');
        $dueDays = (int) ($payload['default_payment_due_days'] ?? 0);

        app(FinancialDistributionValidator::class)->validatePercentages(
            $materialsReserve,
            $businessReserve,
            $commissionType === CommissionType::Percentage ? $commissionValue : '0',
        );

        if ($commissionType === CommissionType::Fixed && bccomp($commissionValue, '0', 4) < 0) {
            throw ValidationException::withMessages([
                'default_commission_value' => 'O valor fixo da comissão não pode ser negativo.',
            ]);
        }

        if ($commissionType === CommissionType::Percentage) {
            if (bccomp($commissionValue, '0', 4) < 0 || bccomp($commissionValue, '100', 4) > 0) {
                throw ValidationException::withMessages([
                    'default_commission_value' => 'O percentual de comissão deve estar entre 0 e 100.',
                ]);
            }
        }

        foreach ([
            'materials_reserve_percentage' => $materialsReserve,
            'business_reserve_percentage' => $businessReserve,
        ] as $field => $value) {
            if (bccomp($value, '0', 4) < 0 || bccomp($value, '100', 4) > 0) {
                throw ValidationException::withMessages([
                    $field => 'O percentual deve estar entre 0 e 100.',
                ]);
            }
        }

        if ($dueDays < 0) {
            throw ValidationException::withMessages([
                'default_payment_due_days' => 'O prazo de pagamento não pode ser negativo.',
            ]);
        }
    }
}
