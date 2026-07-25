<?php

namespace App\Filament\App\Resources\Services\Pages;

use App\Filament\App\Resources\Services\ServiceResource;
use App\Models\Company;
use App\Services\Service\ServiceCatalogService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ServiceCatalogService::class)->create($company, $data);
    }
}
