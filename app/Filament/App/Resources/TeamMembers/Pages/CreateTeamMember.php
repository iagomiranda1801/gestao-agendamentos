<?php

namespace App\Filament\App\Resources\TeamMembers\Pages;

use App\Filament\App\Resources\TeamMembers\TeamMemberResource;
use App\Models\Company;
use App\Services\Company\CompanyTeamService;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTeamMember extends CreateRecord
{
    protected static string $resource = TeamMemberResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */ $company = Filament::getTenant();

        return app(CompanyTeamService::class)->create($company, $data);
    }
}
