<?php

namespace App\Filament\App\Resources\TattooAiConversations\Pages;

use App\Filament\App\Resources\TattooAiConversations\TattooAiConversationResource;
use Filament\Resources\Pages\ListRecords;

class ListTattooAiConversations extends ListRecords
{
    protected static string $resource = TattooAiConversationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
