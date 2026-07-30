<?php

namespace App\Filament\App\Resources\WhatsAppCampaigns\Pages;

use App\Filament\App\Resources\WhatsAppCampaigns\WhatsAppCampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWhatsAppCampaigns extends ListRecords
{
    protected static string $resource = WhatsAppCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova campanha'),
        ];
    }
}
