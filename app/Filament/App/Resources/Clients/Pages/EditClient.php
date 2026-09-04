<?php

namespace App\Filament\App\Resources\Clients\Pages;

use App\Filament\App\Resources\Clients\ClientResource;
use App\Models\Company;
use App\Services\Client\ClientService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use App\Filament\App\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $this->record->loadMissing(['dentalProfile', 'guardians', 'insurances']);

        if ($this->record->dentalProfile !== null) {
            $data['dental_profile'] = $this->record->dentalProfile->only([
                'record_number', 'social_name', 'sex', 'postal_code', 'street', 'street_number',
                'address_complement', 'district', 'city', 'state',
            ]);
        }

        $data['guardians'] = $this->record->guardians->map->only([
            'name', 'document', 'birth_date', 'relationship', 'phone', 'email',
            'is_legal_guardian', 'is_financial_guardian',
        ])->all();
        $data['insurances'] = $this->record->insurances->map->only([
            'provider', 'plan', 'card_number', 'expires_at', 'holder_name', 'notes', 'is_active',
        ])->all();

        return $data;
    }

    protected function getSavedNotification(): ?Notification
    {
        return null;
    }

    protected function afterSave(): void
    {
        Notification::make()
            ->success()
            ->title('Salvo')
            ->send();
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(ClientService::class)->update($company, $record, $data);
    }
}
