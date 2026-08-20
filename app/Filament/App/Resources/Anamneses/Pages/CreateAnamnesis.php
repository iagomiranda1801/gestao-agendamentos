<?php

namespace App\Filament\App\Resources\Anamneses\Pages;

use App\Filament\App\Resources\Anamneses\AnamnesisResource;
use App\Models\Client;
use App\Models\Company;
use App\Services\Clinical\DentalAnamnesisService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateAnamnesis extends CreateRecord
{
    protected static string $resource = AnamnesisResource::class;

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

        return app(DentalAnamnesisService::class)->createDraft($company, $client, auth()->user(), $data['answers'] ?? []);
    }
}
