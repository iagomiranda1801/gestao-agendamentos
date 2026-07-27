<?php

namespace App\Filament\Admin\Resources\Companies\Pages;

use App\Enums\CompanyModule;
use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\Companies\CompanyResource;
use App\Services\Company\CompanyModuleService;
use App\Services\Company\CompanyProvisioningService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['enabled_modules'] = collect($data['enabled_modules'] ?? [CompanyModule::Scheduling->value])
            ->values()
            ->all();

        if (($data['subscription_status'] ?? SubscriptionStatus::Trial->value) === SubscriptionStatus::Trial->value) {
            $data['trial_ends_at'] ??= now()->addDays(7);
        }

        if (($data['subscription_status'] ?? null) === SubscriptionStatus::Active->value) {
            $data['trial_ends_at'] = null;
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $company = parent::handleRecordCreation($data);

        app(CompanyModuleService::class)->syncModules($company, $data['enabled_modules'] ?? []);

        if (in_array(CompanyModule::Scheduling->value, $data['enabled_modules'] ?? [], true)) {
            app(CompanyProvisioningService::class)->provisionSchedulingDefaults($company);
        }

        return $company->refresh();
    }
}
