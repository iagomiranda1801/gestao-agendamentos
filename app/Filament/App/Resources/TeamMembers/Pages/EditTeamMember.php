<?php

namespace App\Filament\App\Resources\TeamMembers\Pages;

use App\Enums\CompanyRole;
use App\Filament\App\Resources\TeamMembers\TeamMemberResource;
use App\Models\Company;
use App\Services\Company\CompanyTeamService;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditTeamMember extends EditRecord
{
    protected static string $resource = TeamMemberResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $membership = $this->record->companies()->where('companies.id', Filament::getTenant()?->getKey())->first()?->pivot;
        $role = $membership?->role;
        $data['role'] = ($role instanceof CompanyRole ? $role : CompanyRole::tryFrom((string) $role))?->value;
        $data['membership_active'] = (bool) $membership?->is_active;
        $data['use_role_defaults'] = $membership?->permissions === null;
        $data['permissions'] = $membership?->permissions ?? [];

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */ $company = Filament::getTenant();

        return app(CompanyTeamService::class)->update($company, $record, $data);
    }
}
