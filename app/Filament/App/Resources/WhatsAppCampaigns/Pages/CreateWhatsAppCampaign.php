<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns\Pages;

use App\Filament\App\Resources\WhatsAppCampaigns\WhatsAppCampaignResource;
use App\Models\Company;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWhatsAppCampaign extends CreateRecord
{
    protected static string $resource = WhatsAppCampaignResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        return app(WhatsAppCampaignService::class)->create($company, auth()->user(), $data);
    }
}
