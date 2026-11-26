<?php

namespace App\Filament\App\Resources\DiningTables\Pages;

use App\Filament\App\Resources\DiningTables\DiningTableResource;
use App\Filament\App\Resources\Pages\CreateRecord;
use App\Models\Company;
use App\Services\Orders\DiningTableService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class CreateDiningTable extends CreateRecord
{
    protected static string $resource = DiningTableResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(DiningTableService::class)->create($company, $data);
    }
}
