<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns\Pages;

use App\Filament\App\Resources\WhatsAppCampaigns\WhatsAppCampaignResource;
use App\Models\Company;
use App\Services\WhatsApp\Campaigns\WhatsAppCampaignService;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateWhatsAppCampaign extends CreateRecord
{
    protected static string $resource = WhatsAppCampaignResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $campaigns = app(WhatsAppCampaignService::class);
        $campaign = $campaigns->create($company, auth()->user(), $data);

        if (($data['delivery_type'] ?? 'now') === 'scheduled') {
            $campaigns->prepareRecipients($company, $campaign);
            $campaigns->schedule($company, $campaign, $data['scheduled_at']);
        } else {
            $campaigns->sendNow($company, $campaign);
        }

        return $campaign;
    }
}
