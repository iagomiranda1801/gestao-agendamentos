<?php

namespace App\Filament\App\Resources\Services\Pages;

use App\Filament\App\Resources\Services\ServiceResource;
use App\Models\Company;
use App\Models\Service;
use App\Services\Service\ServiceCatalogService;
use App\Services\Service\ServiceProfessionalSyncService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditService extends EditRecord
{
    protected static string $resource = ServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Service $service */
        $service = $this->getRecord();

        $data['professional_ids'] = $service->professionals()
            ->wherePivot('company_id', $service->company_id)
            ->pluck('professionals.id')
            ->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $professionalIds = $data['professional_ids'] ?? [];
        unset($data['professional_ids']);

        $service = app(ServiceCatalogService::class)->update($company, $record, $data);

        app(ServiceProfessionalSyncService::class)->sync($company, $service, $professionalIds);

        return $service->refresh();
    }
}
