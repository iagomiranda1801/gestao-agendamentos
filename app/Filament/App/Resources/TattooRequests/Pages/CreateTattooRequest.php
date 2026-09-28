<?php

namespace App\Filament\App\Resources\TattooRequests\Pages;

use App\Filament\App\Resources\Pages\CreateRecord;
use App\Filament\App\Resources\TattooRequests\TattooRequestResource;
use App\Models\Client;
use App\Models\Professional;
use App\Models\TattooRequest;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class CreateTattooRequest extends CreateRecord
{
    protected static string $resource = TattooRequestResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $companyId = Filament::getTenant()->getKey();
        Client::query()->where('company_id', $companyId)->findOrFail($data['client_id']);
        if (! empty($data['professional_id'])) {
            Professional::query()->where('company_id', $companyId)->findOrFail($data['professional_id']);
        }
        $request = new TattooRequest($data);
        $request->company_id = $companyId;
        $request->source = 'manual';
        $request->status = 'awaiting_review';
        $request->save();

        return $request;
    }
}
