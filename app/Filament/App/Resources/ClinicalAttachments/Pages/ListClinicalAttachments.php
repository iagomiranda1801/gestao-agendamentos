<?php

namespace App\Filament\App\Resources\ClinicalAttachments\Pages;

use App\Filament\App\Resources\ClinicalAttachments\ClinicalAttachmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClinicalAttachments extends ListRecords
{
    protected static string $resource = ClinicalAttachmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
