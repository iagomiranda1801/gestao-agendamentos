<?php

namespace App\Filament\App\Resources\Odontograms\Pages;

use App\Filament\App\Resources\Odontograms\OdontogramResource;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Services\Clinical\DentalOdontogramService;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOdontogram extends CreateRecord
{
    protected static string $resource = OdontogramResource::class;

    public function mount(): void
    {
        parent::mount();
        if ($clientId = request()->integer('client_id')) {
            $this->form->fill(array_merge($this->data ?? [], ['client_id' => $clientId]));
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */ $company = Filament::getTenant();
        $client = Client::query()->where('company_id', $company->getKey())->findOrFail($data['client_id']);
        $professional = Professional::query()->where('company_id', $company->getKey())->findOrFail($data['professional_id']);

        return app(DentalOdontogramService::class)->createDraft($company, $client, $professional, auth()->user(), $data['entries'] ?? []);
    }
}
