<?php

namespace App\Filament\App\Resources\StockAdjustments\Pages;

use App\Filament\App\Resources\StockAdjustments\StockAdjustmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStockAdjustments extends ListRecords
{
    protected static string $resource = StockAdjustmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
