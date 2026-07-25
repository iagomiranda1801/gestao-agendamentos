<?php

namespace App\Filament\App\Resources\Services\Pages;

use App\Filament\App\Resources\Services\ServiceResource;
use App\Models\Company;
use App\Services\Service\ServiceCatalogService;
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

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ServiceCatalogService::class)->update($company, $record, $data);
    }
}
