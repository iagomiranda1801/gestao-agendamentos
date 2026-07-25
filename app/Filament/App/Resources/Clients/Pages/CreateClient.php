<?php

namespace App\Filament\App\Resources\Clients\Pages;

use App\Filament\App\Resources\Clients\ClientResource;
use App\Models\Company;
use App\Services\Client\ClientService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateClient extends CreateRecord
{
    protected static string $resource = ClientResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ClientService::class)->create($company, $data);
    }
}
