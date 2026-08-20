<?php

namespace App\Filament\App\Resources\Anamneses\Pages;

use App\Filament\App\Resources\Anamneses\AnamnesisResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAnamneses extends ListRecords
{
    protected static string $resource = AnamnesisResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
