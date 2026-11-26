<?php

namespace App\Filament\App\Resources\DiningTables\Pages;

use App\Filament\App\Resources\DiningTables\DiningTableResource;
use App\Filament\App\Resources\Pages\EditRecord;
use App\Models\Company;
use App\Models\DiningTable;
use App\Services\Orders\DiningTableService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class EditDiningTable extends EditRecord
{
    protected static string $resource = DiningTableResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();
        /** @var DiningTable $record */

        return app(DiningTableService::class)->update($company, $record, $data);
    }
}
