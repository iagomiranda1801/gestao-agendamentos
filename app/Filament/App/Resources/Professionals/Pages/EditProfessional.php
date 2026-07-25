<?php

namespace App\Filament\App\Resources\Professionals\Pages;

use App\Filament\App\Resources\Professionals\ProfessionalResource;
use App\Models\Company;
use App\Services\Professional\ProfessionalService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditProfessional extends EditRecord
{
    protected static string $resource = ProfessionalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ProfessionalService::class)->update($company, $record, $data);
    }
}
