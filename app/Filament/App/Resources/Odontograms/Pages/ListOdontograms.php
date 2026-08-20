<?php

namespace App\Filament\App\Resources\Odontograms\Pages;

use App\Filament\App\Resources\Odontograms\OdontogramResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListOdontograms extends ListRecords
{
    protected static string $resource = OdontogramResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
