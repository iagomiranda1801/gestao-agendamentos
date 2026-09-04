<?php

namespace App\Filament\App\Resources\Services\Pages;

use App\Filament\App\Resources\Services\ServiceResource;
use App\Models\Company;
use App\Services\Service\ServiceCatalogService;
use App\Services\Service\ServiceProfessionalSyncService;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $professionalIds = $data['professional_ids'] ?? [];
        unset($data['professional_ids']);

        $service = app(ServiceCatalogService::class)->create($company, $data);

        app(ServiceProfessionalSyncService::class)->sync($company, $service, $professionalIds);

        return $service->refresh();
    }
}
