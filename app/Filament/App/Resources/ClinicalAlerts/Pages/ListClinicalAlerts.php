<?php

namespace App\Filament\App\Resources\ClinicalAlerts\Pages;

use App\Filament\App\Resources\ClinicalAlerts\ClinicalAlertResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClinicalAlerts extends ListRecords
{
    protected static string $resource = ClinicalAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
