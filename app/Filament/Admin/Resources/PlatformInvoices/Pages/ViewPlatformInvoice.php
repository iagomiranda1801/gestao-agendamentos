<?php

namespace App\Filament\Admin\Resources\PlatformInvoices\Pages;

use App\Filament\Admin\Resources\PlatformInvoices\PlatformInvoiceActions;
use App\Filament\Admin\Resources\PlatformInvoices\PlatformInvoiceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPlatformInvoice extends ViewRecord
{
    protected static string $resource = PlatformInvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PlatformInvoiceActions::pay(),
            PlatformInvoiceActions::markOverdue(),
            PlatformInvoiceActions::cancel(),
        ];
    }
}
