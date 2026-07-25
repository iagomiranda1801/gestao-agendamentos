<?php

namespace App\Filament\App\Resources\Attendances\Pages;

use App\Filament\App\Resources\Attendances\AttendanceResource;
use Filament\Resources\Pages\ListRecords;

class ListAttendances extends ListRecords
{
    protected static string $resource = AttendanceResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
