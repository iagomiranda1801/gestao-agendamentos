<?php

namespace App\Filament\App\Resources\Payables\Pages;

use App\Filament\App\Resources\Payables\PayableResource;
use Filament\Resources\Pages\ListRecords;

class ListPayables extends ListRecords
{
    protected static string $resource = PayableResource::class;
}
