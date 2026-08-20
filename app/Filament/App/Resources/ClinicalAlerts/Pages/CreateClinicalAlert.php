<?php

namespace App\Filament\App\Resources\ClinicalAlerts\Pages;

use App\Filament\App\Resources\ClinicalAlerts\ClinicalAlertResource;
use App\Models\Client;
use App\Models\Company;
use App\Services\Clinical\PatientClinicalAlertService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateClinicalAlert extends CreateRecord
{
    protected static string $resource = ClinicalAlertResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */ $company = Filament::getTenant();
        $client = Client::query()->where('company_id', $company->getKey())->findOrFail($data['client_id']);
        unset($data['client_id']);

        return app(PatientClinicalAlertService::class)->create($company, $client, auth()->user(), $data);
    }
}
