<?php

namespace App\Filament\App\Resources\StockAdjustments\Pages;

use App\Filament\App\Resources\Concerns\EditsStockDocument;
use App\Filament\App\Resources\StockAdjustments\StockAdjustmentResource;
use App\Filament\App\Resources\Pages\EditRecord;

class EditStockAdjustment extends EditRecord
{
    use EditsStockDocument;

    protected static string $resource = StockAdjustmentResource::class;
}
