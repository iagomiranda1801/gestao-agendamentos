<?php

namespace App\Filament\Admin\Resources\PlatformInvoices\Pages;

use App\Filament\Admin\Resources\PlatformInvoices\PlatformInvoiceActions;
use App\Filament\Admin\Resources\PlatformInvoices\PlatformInvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListPlatformInvoices extends ListRecords
{
    protected static string $resource = PlatformInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlatformInvoiceActions::issue(),
        ];
    }
}
