<?php

namespace Tests\Feature\Stock;

use App\Enums\StockDocumentType;
use App\Enums\StockMovementDirection;
use App\Models\InventoryBalance;
use App\Models\StockMovement;
use App\Services\Stock\StockDocumentPostingService;
use App\Services\Stock\StockDocumentService;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class WeightedAverageCostTest extends TestCase
{
    use CreatesStockFixtures;

    public function test_opening_balance_of_ten_at_two_produces_average_two(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $balance = InventoryBalance::query()->where('product_id', $product->getKey())->first();

        $this->assertSame('10.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.000000', (string) $balance->average_unit_cost);
        $this->assertSame('20.000000', $balance->totalInventoryValue());
    }

    public function test_purchase_updates_average_to_three(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '4.000000',
        ]]);

        $balance = InventoryBalance::query()->where('product_id', $product->getKey())->first();

        $this->assertSame('20.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('3.000000', (string) $balance->average_unit_cost);
        $this->assertSame('60.000000', $balance->totalInventoryValue());
    }

    public function test_outbound_keeps_average_and_calculates_exit_cost(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '4.000000',
        ]]);

        $document = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::ManualExit,
            ['occurred_at' => now(), 'notes' => 'Saída de teste'],
            [[
                'product_id' => $product->getKey(),
                'quantity' => '5',
            ]],
            $user,
        );

        app(StockDocumentPostingService::class)->post($company, $document, $user);

        $balance = InventoryBalance::query()->where('product_id', $product->getKey())->first();
        $movement = StockMovement::query()
            ->where('stock_document_id', $document->getKey())
            ->first();

        $this->assertSame('15.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('3.000000', (string) $balance->average_unit_cost);
        $this->assertSame('45.000000', $balance->totalInventoryValue());
        $this->assertSame(StockMovementDirection::Outbound, $movement->direction);
        $this->assertSame('15.000000', (string) $movement->total_cost);
    }

    public function test_zero_quantity_keeps_last_average_cost(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '5',
            'unit_cost' => '3.000000',
        ]]);

        $exit = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::ManualExit,
            ['occurred_at' => now(), 'notes' => 'Esvaziar estoque'],
            [[
                'product_id' => $product->getKey(),
                'quantity' => '5',
            ]],
            $user,
        );

        app(StockDocumentPostingService::class)->post($company, $exit, $user);

        $balance = InventoryBalance::query()->where('product_id', $product->getKey())->first();

        $this->assertSame('0.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('3.000000', (string) $balance->average_unit_cost);
    }

    public function test_new_entry_after_zero_recalculates_average(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '5',
            'unit_cost' => '3.000000',
        ]]);

        $exit = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::ManualExit,
            ['occurred_at' => now(), 'notes' => 'Esvaziar'],
            [['product_id' => $product->getKey(), 'quantity' => '5']],
            $user,
        );
        app(StockDocumentPostingService::class)->post($company, $exit, $user);

        $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '5.000000',
        ]]);

        $balance = InventoryBalance::query()->where('product_id', $product->getKey())->first();

        $this->assertSame('10.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('5.000000', (string) $balance->average_unit_cost);
    }
}
