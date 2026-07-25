<?php

namespace Tests\Feature\Stock;

use App\Enums\StockDocumentType;
use App\Models\InventoryBalance;
use App\Models\StockMovement;
use App\Services\Stock\StockDocumentPostingService;
use App\Services\Stock\StockDocumentService;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class InventoryCountTest extends TestCase
{
    use CreatesStockFixtures;

    public function test_equal_count_does_not_create_movement(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $document = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::InventoryCount,
            ['occurred_at' => now()],
            [[
                'product_id' => $product->getKey(),
                'counted_quantity' => '10',
            ]],
            $user,
        );

        app(StockDocumentPostingService::class)->post($company, $document, $user);

        $this->assertSame(1, StockMovement::query()->where('product_id', $product->getKey())->count());
        $this->assertSame('10.0000', (string) InventoryBalance::query()->where('product_id', $product->getKey())->value('quantity_on_hand'));
    }

    public function test_higher_count_creates_inbound_movement(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $document = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::InventoryCount,
            ['occurred_at' => now()],
            [[
                'product_id' => $product->getKey(),
                'counted_quantity' => '15',
            ]],
            $user,
        );

        app(StockDocumentPostingService::class)->post($company, $document, $user);

        $this->assertSame(2, StockMovement::query()->where('product_id', $product->getKey())->count());
        $this->assertSame('15.0000', (string) InventoryBalance::query()->where('product_id', $product->getKey())->value('quantity_on_hand'));
    }

    public function test_lower_count_creates_outbound_movement(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $document = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::InventoryCount,
            ['occurred_at' => now()],
            [[
                'product_id' => $product->getKey(),
                'counted_quantity' => '7',
            ]],
            $user,
        );

        app(StockDocumentPostingService::class)->post($company, $document, $user);

        $this->assertSame('7.0000', (string) InventoryBalance::query()->where('product_id', $product->getKey())->value('quantity_on_hand'));
    }

    public function test_expected_quantity_is_set_at_posting_time(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $document = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::InventoryCount,
            ['occurred_at' => now()],
            [[
                'product_id' => $product->getKey(),
                'counted_quantity' => '12',
                'expected_quantity' => '999',
            ]],
            $user,
        );

        app(StockDocumentPostingService::class)->post($company, $document, $user);

        $item = $document->items()->first();

        $this->assertSame('10.0000', (string) $item->expected_quantity);
        $this->assertSame('2.0000', (string) $item->quantity_delta);
    }
}
