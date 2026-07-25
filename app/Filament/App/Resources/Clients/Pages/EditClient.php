<?php

namespace App\Filament\App\Resources\Clients\Pages;

use App\Filament\App\Resources\Clients\ClientResource;
use App\Models\Company;
use App\Services\Client\ClientService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Salvo';
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ClientService::class)->update($company, $record, $data);
    }
}
