<?php

namespace App\Filament\App\Resources\WhatsAppBotConversations\Pages;

use App\Filament\App\Resources\WhatsAppBotConversations\WhatsAppBotConversationResource;
use Filament\Resources\Pages\ListRecords;

class ListWhatsAppBotConversations extends ListRecords
{
    protected static string $resource = WhatsAppBotConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
