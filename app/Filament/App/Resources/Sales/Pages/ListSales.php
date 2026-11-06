<?php

namespace App\Filament\App\Resources\Sales\Pages;

use App\Filament\App\Pages\AsaasSettingsPage;
use App\Filament\App\Resources\Sales\SaleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListSales extends ListRecords
{
    protected static string $resource = SaleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fiscalSettings')
                ->label('Configurar notas fiscais')
                ->visible(fn (): bool => AsaasSettingsPage::canAccess())
                ->url(fn (): string => AsaasSettingsPage::fiscalSettingsUrl()),
        ];
    }
}
