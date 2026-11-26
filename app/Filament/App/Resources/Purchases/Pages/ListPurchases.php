<?php

namespace App\Filament\App\Resources\Purchases\Pages;

use App\Filament\App\Pages\ImportNfePage;
use App\Filament\App\Resources\Purchases\PurchaseResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPurchases extends ListRecords
{
    protected static string $resource = PurchaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importNfe')
                ->label('Importar XML da NF-e')
                ->icon(Heroicon::OutlinedDocumentArrowUp)
                ->color('gray')
                ->url(fn (): string => ImportNfePage::getUrl())
                ->visible(fn (): bool => ImportNfePage::canAccess()),
            CreateAction::make(),
        ];
    }
}
