<?php

namespace App\Filament\App\Resources\ClinicalAttachments\Pages;

use App\Filament\App\Resources\ClinicalAttachments\ClinicalAttachmentResource;
use App\Models\Client;
use App\Models\Company;
use App\Services\Clinical\ClinicalAttachmentService;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateClinicalAttachment extends CreateRecord
{
    protected static string $resource = ClinicalAttachmentResource::class;

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

        return app(ClinicalAttachmentService::class)->upload(
            $company,
            $client,
            auth()->user(),
            $data['file'],
            $data['type'],
            $data['title'],
            description: $data['description'] ?? null,
            documentDate: $data['document_date'] ?? null,
        );
    }
}
