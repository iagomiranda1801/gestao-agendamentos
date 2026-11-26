<?php

namespace App\Filament\App\Resources\DiningTables\Pages;

use App\Filament\App\Resources\DiningTables\DiningTableResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDiningTables extends ListRecords
{
    protected static string $resource = DiningTableResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nova mesa'),
        ];
    }
}
