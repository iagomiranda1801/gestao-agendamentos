<?php

namespace App\Filament\App\Resources\WhatsAppInstances\Pages;

use App\Filament\App\Resources\WhatsAppInstances\WhatsAppInstanceResource;
use App\Models\Company;
use App\Services\WhatsApp\CompanyWhatsAppInstanceService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditWhatsAppInstance extends EditRecord
{
    protected static string $resource = WhatsAppInstanceResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(CompanyWhatsAppInstanceService::class)->update($company, $record, $data);
    }
}
