<?php

namespace App\Services\Clinical;

use App\Models\Company;
use App\Models\DentalClinicSetting;
use Illuminate\Validation\ValidationException;

class DentalClinicSettingService
{
    public function getOrCreate(Company $company): DentalClinicSetting
    {
        $setting = DentalClinicSetting::query()->where('company_id', $company->getKey())->first();
        if ($setting !== null) {
            return $setting;
        }

        $setting = new DentalClinicSetting([
            'professional_record_scope' => 'all',
            'minor_guardian_required' => false,
            'clinical_entry_required_to_complete' => false,
        ]);
        $setting->company_id = $company->getKey();
        $setting->save();

        return $setting->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(Company $company, array $data): DentalClinicSetting
    {
        if (! in_array($data['professional_record_scope'] ?? 'all', ['all', 'related'], true)) {
            throw ValidationException::withMessages(['professional_record_scope' => 'Escopo de prontuários inválido.']);
        }
        $setting = $this->getOrCreate($company);
        $setting->fill($data)->save();

        return $setting->refresh();
    }
}
