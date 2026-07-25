<?php

namespace App\Filament\App\Resources\Professionals\Pages;

use App\Filament\App\Resources\Professionals\ProfessionalResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProfessionals extends ListRecords
{
    protected static string $resource = ProfessionalResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
