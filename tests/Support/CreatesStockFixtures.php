<?php

namespace Tests\Support;

use App\Enums\StockDocumentType;
use App\Models\Company;
use App\Models\MeasurementUnit;
use App\Models\Product;
use App\Models\StockDocument;
use App\Models\User;
use App\Services\Stock\StockDocumentPostingService;
use App\Services\Stock\StockDocumentService;

trait CreatesStockFixtures
{
    protected function createTrackedProduct(Company $company, array $attributes = []): Product
    {
        $unit = MeasurementUnit::factory()->create(['is_active' => true]);

        return Product::factory()->forCompany($company)->create(array_merge([
            'measurement_unit_id' => $unit->getKey(),
            'tracks_stock' => true,
            'is_active' => true,
            'reference_unit_cost' => '2.000000',
        ], $attributes));
    }

    /**
     * @param  array<int, array{product_id: int, quantity: string, unit_cost: string}>  $items
     */
    protected function postOpeningBalance(Company $company, User $user, array $items): StockDocument
    {
        $document = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::OpeningBalance,
            ['occurred_at' => now(), 'notes' => 'Saldo inicial de teste'],
            $items,
            $user,
        );

        return app(StockDocumentPostingService::class)->post($company, $document, $user);
    }

    /**
     * @param  array<int, array{product_id: int, quantity: string, unit_cost: string}>  $items
     */
    protected function postPurchase(Company $company, User $user, array $items, array $header = []): StockDocument
    {
        $document = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::Purchase,
            array_merge(['occurred_at' => now()], $header),
            $items,
            $user,
        );

        return app(StockDocumentPostingService::class)->post($company, $document, $user);
    }
}
