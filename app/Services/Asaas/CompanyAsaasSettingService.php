<?php

namespace App\Services\Asaas;

use App\Enums\AsaasEnvironment;
use App\Models\Company;
use App\Models\CompanyAsaasSetting;
use App\Models\FinancialAccount;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CompanyAsaasSettingService
{
    public function getOrCreate(Company $company): CompanyAsaasSetting
    {
        $setting = CompanyAsaasSetting::query()
            ->where('company_id', $company->getKey())
            ->first();

        if ($setting !== null) {
            return $setting;
        }

        $setting = new CompanyAsaasSetting([
            'environment' => AsaasEnvironment::Sandbox,
            'webhook_token' => Str::random(48),
            'is_enabled' => false,
        ]);
        $setting->company()->associate($company);

        try {
            $setting->save();
        } catch (UniqueConstraintViolationException) {
            return CompanyAsaasSetting::query()
                ->where('company_id', $company->getKey())
                ->firstOrFail();
        }

        return $setting->refresh();
    }

    public function ready(Company $company): ?CompanyAsaasSetting
    {
        $setting = CompanyAsaasSetting::query()
            ->where('company_id', $company->getKey())
            ->first();

        return $setting?->isReady() ? $setting : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Company $company, array $data): CompanyAsaasSetting
    {
        return DB::transaction(function () use ($company, $data): CompanyAsaasSetting {
            $setting = $this->getOrCreate($company);
            $key = trim((string) ($data['api_key'] ?? ''));

            if ($key !== '') {
                $setting->api_key = $key;
            }

            foreach (['fiscal_sandbox_api_key', 'base_sandbox_api_key'] as $field) {
                $fiscalKey = trim((string) ($data[$field] ?? ''));
                if ($fiscalKey !== '') {
                    $setting->{$field} = $fiscalKey;
                }
            }

            $enabled = (bool) ($data['is_enabled'] ?? false);
            if ($enabled && blank($setting->api_key)) {
                throw ValidationException::withMessages([
                    'api_key' => 'Informe a chave de API para ativar a integração.',
                ]);
            }

            $accountId = $data['financial_account_id'] ?? null;
            if (blank($accountId)) {
                $setting->financial_account_id = null;
            } else {
                $account = FinancialAccount::query()
                    ->whereKey($accountId)
                    ->where('company_id', $company->getKey())
                    ->where('is_active', true)
                    ->first();

                if ($account === null) {
                    throw ValidationException::withMessages([
                        'financial_account_id' => 'A conta financeira informada não pertence a esta empresa.',
                    ]);
                }

                $setting->financialAccount()->associate($account);
            }

            $setting->environment = AsaasEnvironment::from((string) $data['environment']);
            $setting->is_enabled = $enabled;
            $setting->save();

            return $setting->refresh();
        });
    }
}
