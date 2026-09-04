<?php

namespace App\Filament\App\Resources\Purchases\Pages;

use App\Enums\StockDocumentType;
use App\Filament\App\Resources\Pages\CreateRecord;
use App\Filament\App\Resources\Purchases\PurchaseResource;
use App\Models\Company;
use App\Services\Stock\StockDocumentService;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

class CreatePurchase extends CreateRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        /** @var Company $company */
        $company = Filament::getTenant();

        $items = $data['items'] ?? [];
        unset($data['items']);

        return app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::Purchase,
            $data,
            $items,
            auth()->user(),
        );
    }
}
