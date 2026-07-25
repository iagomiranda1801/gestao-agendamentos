<?php

namespace Tests\Feature\Stock;

use App\Enums\StockDocumentType;
use App\Enums\StockMovementDirection;
use App\Models\InventoryBalance;
use App\Models\StockMovement;
use App\Services\Stock\StockDocumentPostingService;
use App\Services\Stock\StockDocumentService;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class OpeningBalanceTest extends TestCase
{
    use CreatesStockFixtures;

    public function test_opening_balance_creates_inbound_movement(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $this->assertSame(1, StockMovement::query()->where('product_id', $product->getKey())->count());
        $this->assertSame(
            StockMovementDirection::Inbound,
            StockMovement::query()->where('product_id', $product->getKey())->first()->direction,
        );
    }

    public function test_opening_balance_creates_inventory_balance(): void
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

        $this->assertNotNull($balance);
        $this->assertSame('10.0000', (string) $balance->quantity_on_hand);
        $this->assertSame('2.000000', (string) $balance->average_unit_cost);
    }

    public function test_product_with_prior_movement_cannot_receive_opening_balance(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $this->expectException(ValidationException::class);

        app(StockDocumentPostingService::class)->post(
            $company,
            app(StockDocumentService::class)->createDraft(
                $company,
                StockDocumentType::OpeningBalance,
                ['occurred_at' => now()],
                [[
                    'product_id' => $product->getKey(),
                    'quantity' => '5',
                    'unit_cost' => '1.000000',
                ]],
                $user,
            ),
            $user,
        );
    }

    public function test_zero_quantity_is_prevented(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->expectException(ValidationException::class);

        app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::OpeningBalance,
            ['occurred_at' => now()],
            [[
                'product_id' => $product->getKey(),
                'quantity' => '0',
                'unit_cost' => '2.000000',
            ]],
            $user,
        );
    }

    public function test_posted_document_cannot_be_edited(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $document = $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $this->expectException(ValidationException::class);

        app(StockDocumentService::class)->updateDraft($company, $document, [
            'occurred_at' => now(),
            'notes' => 'Alterado',
        ], [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);
    }

    public function test_document_cannot_be_posted_twice(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $document = $this->postOpeningBalance($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $this->expectException(ValidationException::class);

        app(StockDocumentPostingService::class)->post($company, $document, $user);
    }

    public function test_product_without_stock_tracking_is_prevented(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company, ['tracks_stock' => false]);

        $this->expectException(ValidationException::class);

        app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::OpeningBalance,
            ['occurred_at' => now()],
            [[
                'product_id' => $product->getKey(),
                'quantity' => '10',
                'unit_cost' => '2.000000',
            ]],
            $user,
        );
    }
}
