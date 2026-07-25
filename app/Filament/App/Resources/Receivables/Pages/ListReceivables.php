<?php

namespace App\Filament\App\Resources\Receivables\Pages;

use App\Filament\App\Resources\Receivables\ReceivableResource;
use Filament\Resources\Pages\ListRecords;

class ListReceivables extends ListRecords
{
    protected static string $resource = ReceivableResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
