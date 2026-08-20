<?php

namespace App\Filament\App\Resources\TreatmentPlans\Pages;

use App\Filament\App\Resources\TreatmentPlans\TreatmentPlanResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTreatmentPlans extends ListRecords
{
    protected static string $resource = TreatmentPlanResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
