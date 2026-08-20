<?php

namespace App\Filament\App\Resources\ClinicalEntries\Pages;

use App\Filament\App\Resources\ClinicalEntries\ClinicalEntryResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClinicalEntries extends ListRecords
{
    protected static string $resource = ClinicalEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
