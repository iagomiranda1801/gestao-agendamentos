<?php

namespace Tests\Feature\Stock;

use App\Enums\StockDocumentStatus;
use App\Enums\StockDocumentType;
use App\Filament\App\Resources\Purchases\Pages\EditPurchase;
use App\Models\InventoryBalance;
use App\Models\Payable;
use App\Models\StockMovement;
use App\Services\Stock\StockDocumentPostingService;
use App\Services\Stock\StockDocumentReversalService;
use App\Services\Stock\StockDocumentService;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CreatesFinanceFixtures;
use Tests\Support\CreatesStockFixtures;
use Tests\TestCase;

class PurchaseAndReversalTest extends TestCase
{
    use CreatesFinanceFixtures;
    use CreatesStockFixtures;

    public function test_draft_purchase_does_not_change_stock(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::Purchase,
            ['occurred_at' => now()],
            [[
                'product_id' => $product->getKey(),
                'quantity' => '10',
                'unit_cost' => '2.000000',
            ]],
            $user,
        );

        $this->assertNull(InventoryBalance::query()->where('product_id', $product->getKey())->first());
    }

    public function test_posted_purchase_creates_movements_and_updates_stock(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $document = $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $this->assertSame(StockDocumentStatus::Posted, $document->status);
        $this->assertSame(1, StockMovement::query()->where('stock_document_id', $document->getKey())->count());
        $this->assertSame('10.0000', (string) InventoryBalance::query()->where('product_id', $product->getKey())->value('quantity_on_hand'));
    }

    public function test_purchase_edit_page_can_post_draft_purchase(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $document = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::Purchase,
            ['occurred_at' => now()],
            [[
                'product_id' => $product->getKey(),
                'quantity' => '10',
                'unit_cost' => '2.000000',
            ]],
            $user,
        );

        $this->authenticateForAppTenant($user, $company);

        Livewire::test(EditPurchase::class, [
            'record' => $document->getRouteKey(),
        ])
            ->assertActionVisible('post')
            ->callAction('post')
            ->assertHasNoActionErrors();

        $this->assertSame(StockDocumentStatus::Posted, $document->fresh()->status);
        $this->assertSame('10.0000', (string) InventoryBalance::query()->where('product_id', $product->getKey())->value('quantity_on_hand'));
    }

    public function test_purchase_edit_page_can_generate_payable_for_posted_purchase(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);
        $category = $this->createStockPurchaseCategory($company);

        $document = $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '5',
            'unit_cost' => '20.000000',
        ]]);

        $this->authenticateForAppTenant($user, $company);

        Livewire::test(EditPurchase::class, [
            'record' => $document->getRouteKey(),
        ])
            ->assertSuccessful()
            ->assertActionVisible('generatePayable')
            ->callAction('generatePayable', [
                'expense_category_id' => $category->getKey(),
                'issue_date' => now()->toDateString(),
                'competence_date' => now()->toDateString(),
                'installment_count' => 1,
                'first_due_date' => now()->addDays(30)->toDateString(),
                'installment_interval_days' => 30,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, Payable::query()->where('stock_document_id', $document->getKey())->count());
    }

    public function test_reversal_creates_inverse_movements_and_marks_original_reversed(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $document = $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $reversal = app(StockDocumentReversalService::class)->reverse(
            $company,
            $document,
            $user,
            'Erro no lançamento',
        );

        $document->refresh();

        $this->assertSame(StockDocumentStatus::Reversed, $document->status);
        $this->assertSame(StockDocumentType::Reversal, $reversal->type);
        $this->assertSame('0.0000', (string) InventoryBalance::query()->where('product_id', $product->getKey())->value('quantity_on_hand'));
        $this->assertSame(2, StockMovement::query()->where('product_id', $product->getKey())->count());
    }

    public function test_reversal_requires_reason(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $document = $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        $this->expectException(ValidationException::class);

        app(StockDocumentReversalService::class)->reverse($company, $document, $user, '  ');
    }

    public function test_document_cannot_be_reversed_twice(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $document = $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '10',
            'unit_cost' => '2.000000',
        ]]);

        app(StockDocumentReversalService::class)->reverse($company, $document, $user, 'Primeiro estorno');

        $this->expectException(ValidationException::class);

        app(StockDocumentReversalService::class)->reverse($company, $document->fresh(), $user, 'Segundo estorno');
    }

    public function test_outbound_greater_than_stock_is_prevented(): void
    {
        $company = $this->createCompany();
        $user = $this->createCompanyUser($company);
        $product = $this->createTrackedProduct($company);

        $this->postPurchase($company, $user, [[
            'product_id' => $product->getKey(),
            'quantity' => '5',
            'unit_cost' => '2.000000',
        ]]);

        $document = app(StockDocumentService::class)->createDraft(
            $company,
            StockDocumentType::ManualExit,
            ['occurred_at' => now(), 'notes' => 'Saída inválida'],
            [['product_id' => $product->getKey(), 'quantity' => '10']],
            $user,
        );

        $this->expectException(ValidationException::class);

        app(StockDocumentPostingService::class)->post($company, $document, $user);
    }
}
