<?php

namespace App\Filament\App\Resources\Services\Pages;

use App\Filament\App\Resources\Pages\CreateRecord;
use App\Filament\App\Resources\Services\ServiceResource;
use App\Models\Company;
use App\Services\Service\ServiceCatalogService;
use App\Services\Service\ServiceProfessionalSyncService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CreateService extends CreateRecord
{
    protected static string $resource = ServiceResource::class;

    public function mount(): void
    {
        parent::mount();

        $company = Filament::getTenant();
        if (! $company instanceof Company || ! $company->isPersonalTrainer()) {
            return;
        }

        $name = match (request()->query('personal_template')) {
            'initial_assessment' => 'Avaliação inicial',
            'individual_training' => 'Treino individual',
            default => null,
        };

        if ($name !== null) {
            $this->form->fill(array_merge($this->form->getRawState(), [
                'name' => $name,
                'slug' => Str::slug($name),
            ]));
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $professionalIds = $data['professional_ids'] ?? [];
        unset($data['professional_ids']);

        $service = app(ServiceCatalogService::class)->create($company, $data);

        app(ServiceProfessionalSyncService::class)->sync($company, $service, $professionalIds);

        return $service->refresh();
    }
}
