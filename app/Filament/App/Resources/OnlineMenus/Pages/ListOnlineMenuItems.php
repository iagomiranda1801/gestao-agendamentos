<?php

namespace App\Filament\App\Resources\OnlineMenus\Pages;

use App\Filament\App\Resources\OnlineMenus\OnlineMenuResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOnlineMenuItems extends ListRecords
{
    protected static string $resource = OnlineMenuResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Novo item'),
        ];
    }
}
