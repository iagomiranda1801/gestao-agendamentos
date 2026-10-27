<?php

namespace App\Filament\App\Resources\Appointments\Pages;

use App\Filament\App\Resources\Appointments\AppointmentResource;
use App\Filament\App\Resources\Appointments\Concerns\ProvidesExceptionAppointmentActions;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAppointments extends ListRecords
{
    use ProvidesExceptionAppointmentActions;

    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ...$this->exceptionAppointmentHeaderActions(),
        ];
    }
}
