<?php

namespace App\Filament\App\Resources\Professionals\Pages;

use App\Filament\App\Resources\Professionals\ProfessionalResource;
use App\Models\Company;
use App\Services\Professional\ProfessionalService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateProfessional extends CreateRecord
{
    protected static string $resource = ProfessionalResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ProfessionalService::class)->create($company, $data);
    }
}
