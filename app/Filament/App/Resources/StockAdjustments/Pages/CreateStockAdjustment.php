<?php

namespace App\Filament\App\Resources\StockAdjustments\Pages;

use App\Enums\StockDocumentType;
use App\Filament\App\Resources\StockAdjustments\StockAdjustmentResource;
use App\Models\Company;
use App\Services\Stock\StockDocumentService;
use Filament\Facades\Filament;
use App\Filament\App\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStockAdjustment extends CreateRecord
{
    protected static string $resource = StockAdjustmentResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $type = StockDocumentType::from($data['type']);
        $items = $data['items'] ?? [];
        unset($data['items']);

        return app(StockDocumentService::class)->createDraft(
            $company,
            $type,
            $data,
            $items,
            auth()->user(),
        );
    }
}
