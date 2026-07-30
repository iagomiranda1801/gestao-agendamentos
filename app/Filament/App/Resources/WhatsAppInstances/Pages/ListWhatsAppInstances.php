<?php

namespace App\Filament\App\Resources\WhatsAppInstances\Pages;

use App\Filament\App\Resources\WhatsAppInstances\WhatsAppInstanceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWhatsAppInstances extends ListRecords
{
    protected static string $resource = WhatsAppInstanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nova conexão'),
        ];
    }
}
