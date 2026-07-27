<?php

namespace App\Filament\Admin\Resources\Companies\Pages;

use App\Enums\SubscriptionStatus;
use App\Filament\Admin\Resources\Companies\CompanyResource;
use App\Services\Company\CompanyModuleService;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['subscription_status'] ?? null) === SubscriptionStatus::Active->value) {
            $data['trial_ends_at'] = null;
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $company = parent::handleRecordUpdate($record, $data);

        if (isset($data['enabled_modules'])) {
            app(CompanyModuleService::class)->syncModules($company, $data['enabled_modules']);
        }

        return $company->refresh();
    }
}
