<?php

namespace App\Filament\App\Resources\TreatmentPlans\Pages;

use App\Filament\App\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Models\Client;
use App\Models\Company;
use App\Models\Professional;
use App\Services\Clinical\DentalTreatmentPlanService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateTreatmentPlan extends CreateRecord
{
    protected static string $resource = TreatmentPlanResource::class;

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
        $items = $data['items'] ?? [];
        unset($data['client_id'], $data['professional_id'], $data['items']);

        return app(DentalTreatmentPlanService::class)->create($company, $client, $professional, auth()->user(), $data, $items);
    }
}
